<?php

/**
 * @package KantPHP
 * @author  Zhenqiang Zhang <zhenqiang.zhang@hotmail.com>
 * @copyright (c) KantPHP Studio, All rights reserved.
 * @license http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 */
namespace Kant\Identity;

use Kant\Foundation\Component;
use Kant\Kant;
use Kant\Cookie\Cookie;
use Kant\Exception\InvalidValueException;
use Kant\Exception\InvalidConfigException;

class User extends Component
{

    const EVENT_BEFORE_LOGIN = 'beforeLogin';

    const EVENT_AFTER_LOGIN = 'afterLogin';

    const EVENT_BEFORE_LOGOUT = 'beforeLogout';

    const EVENT_AFTER_LOGOUT = 'afterLogout';

    /**
     *
     * @var string the class name of the [[identity]] object.
     */
    public $identityClass;

    /**
     *
     * @var boolean whether to enable cookie-based login. Defaults to false.
     *      Note that this property will be ignored if [[enableSession]] is false.
     */
    public $enableAutoLogin = false;

    /**
     *
     * @var boolean whether to use session to persist authentication status across multiple requests.
     *      You set this property to be false if your application is stateless, which is often the case
     *      for RESTful APIs.
     */
    public $enableSession = true;

    /**
     *
     * @var array the configuration of the identity cookie. This property is used only when [[enableAutoLogin]] is true.
     * @see Cookie
     */
    public $identityCookie = [
        'name' => '_identity',
        'httpOnly' => true
    ];

    /**
     *
     * @var integer the number of seconds in which the user will be logged out automatically if he
     *      remains inactive. If this property is not set, the user will be logged out after
     *      the current session expires (c.f. [[Session::timeout]]).
     *      Note that this will not work if [[enableAutoLogin]] is true.
     */
    public $authTimeout;

    /**
     *
     * @var integer the number of seconds in which the user will be logged out automatically
     *      regardless of activity.
     *      Note that this will not work if [[enableAutoLogin]] is true.
     */
    public $absoluteAuthTimeout;

    /**
     *
     * @var boolean whether to automatically renew the identity cookie each time a page is requested.
     *      This property is effective only when [[enableAutoLogin]] is true.
     *      When this is false, the identity cookie will expire after the specified duration since the user
     *      is initially logged in. When this is true, the identity cookie will expire after the specified duration
     *      since the user visits the site the last time.
     * @see enableAutoLogin
     */
    public $autoRenewCookie = true;

    /**
     *
     * @var string the session variable name used to store the value of [[id]].
     */
    public $idParam = '__id';

    /**
     *
     * @var string the session variable name used to store the value of expiration timestamp of the authenticated state.
     *      This is used when [[authTimeout]] is set.
     */
    public $authTimeoutParam = '__expire';

    /**
     *
     * @var string the session variable name used to store the value of absolute expiration timestamp of the authenticated state.
     *      This is used when [[absoluteAuthTimeout]] is set.
     */
    public $absoluteAuthTimeoutParam = '__absoluteExpire';

    private $_access = [];

    private $_identity = false;

    /**
     * Initializes the application component.
     */
    public function init()
    {
        parent::init();
        
        if ($this->identityClass === null) {
            throw new InvalidConfigException('User::identityClass must be set.');
        }
        if ($this->enableAutoLogin && ! isset($this->identityCookie['name'])) {
            throw new InvalidConfigException('User::identityCookie must contain the "name" element.');
        }
    }

    /**
     * Returns the identity object associated with the currently logged-in user.
     * When [[enableSession]] is true, this method may attempt to read the user's authentication data
     * stored in session and reconstruct the corresponding identity object, if it has not done so before.
     * 
     * @param boolean $autoRenew
     *            whether to automatically renew authentication status if it has not been done so before.
     *            This is only useful when [[enableSession]] is true.
     * @return IdentityInterface|null the identity object associated with the currently logged-in user.
     *         `null` is returned if the user is not logged in (not authenticated).
     * @see login()
     * @see logout()
     */
    public function getIdentity($autoRenew = true)
    {
        if ($this->_identity === false) {
            if ($this->enableSession && $autoRenew) {
                $this->_identity = null;
                $this->renewAuthStatus();
            } else {
                return null;
            }
        }
        
        return $this->_identity;
    }

    /**
     * Sets the user identity object.
     *
     * Note that this method does not deal with session or cookie. You should usually use [[switchIdentity()]]
     * to change the identity of the current user.
     *
     * @param IdentityInterface|null $identity
     *            the identity object associated with the currently logged user.
     *            If null, it means the current user will be a guest without any associated identity.
     * @throws InvalidValueException if `$identity` object does not implement [[IdentityInterface]].
     */
    public function setIdentity($identity)
    {
        if ($identity instanceof IdentityInterface) {
            $this->_identity = $identity;
            $this->_access = [];
        } elseif ($identity === null) {
            $this->_identity = null;
        } else {
            throw new InvalidValueException('The identity object must implement IdentityInterface.');
        }
    }

    /**
     * Logs in a user.
     *
     * After logging in a user, you may obtain the user's identity information from the [[identity]] property.
     * If [[enableSession]] is true, you may even get the identity information in the next requests without
     * calling this method again.
     *
     * The login status is maintained according to the `$duration` parameter:
     *
     * - `$duration == 0`: the identity information will be stored in session and will be available
     * via [[identity]] as long as the session remains active.
     * - `$duration > 0`: the identity information will be stored in session. If [[enableAutoLogin]] is true,
     * it will also be stored in a cookie which will expire in `$duration` seconds. As long as
     * the cookie remains valid or the session is active, you may obtain the user identity information
     * via [[identity]].
     *
     * Note that if [[enableSession]] is false, the `$duration` parameter will be ignored as it is meaningless
     * in this case.
     *
     * @param IdentityInterface $identity
     *            the user identity (which should already be authenticated)
     * @param integer $minutes
     *            number of minutes that the user can remain in logged-in status.
     *            Defaults to 0, meaning login till the user closes the browser or the session is manually destroyed.
     *            If greater than 0 and [[enableAutoLogin]] is true, cookie-based login will be supported.
     *            Note that if [[enableSession]] is false, this parameter will be ignored.
     * @return boolean whether the user is logged in
     */
    public function login(IdentityInterface $identity, $minutes = 0)
    {
        if ($this->beforeLogin($identity, false, $minutes)) {
            $this->switchIdentity($identity, $minutes);
            $id = $identity->getId();
            $ip = Kant::$app->getRequest()->ip();
            if ($this->enableSession) {
                $log = "Manager '$id' logged in from $ip with duration $minutes minutes.";
            } else {
                $log = "Manager '$id' logged in from $ip. Session not enabled.";
            }
            Kant::info($log, __METHOD__);
            $this->afterLogin($identity, false, $minutes);
        }
        
        return ! $this->getIsGuest();
    }

    /**
     * Logs in a user by cookie.
     *
     * This method attempts to log in a user using the ID and authKey information
     * provided by the [[identityCookie|identity cookie]].
     */
    protected function loginByCookie()
    {
        $data = $this->getIdentityAndDurationFromCookie();
        if (isset($data['identity'], $data['duration'])) {
            $identity = $data['identity'];
            $duration = $data['duration'];
            if ($this->beforeLogin($identity, true, $duration)) {
                $this->switchIdentity($identity, $this->autoRenewCookie ? $duration : 0);
                $id = $identity->getId();
                $ip = Kant::$app->getRequest()->ip();
                Kant::info("User '$id' logged in from $ip via cookie.", __METHOD__);
                $this->afterLogin($identity, true, $duration);
            }
        }
    }

    /**
     * Logs out the current user.
     * This will remove authentication-related session data.
     * If `$destroySession` is true, all session data will be removed.
     * 
     * @param boolean $destroySession
     *            whether to destroy the whole session. Defaults to true.
     *            This parameter is ignored if [[enableSession]] is false.
     * @return boolean whether the user is logged out
     */
    public function logout($destroySession = true)
    {
        $identity = $this->getIdentity();
        if ($identity !== null && $this->beforeLogout($identity)) {
            $this->switchIdentity(null);
            $id = $identity->getId();
            $ip = Kant::$app->getRequest()->ip();
            Kant::info("User '$id' logged out from $ip.", __METHOD__);
            if ($destroySession && $this->enableSession) {
                Kant::$app->getSession()->flush();
            }
            $this->afterLogout($identity);
        }
        
        return $this->getIsGuest();
    }

    /**
     * Returns a value indicating whether the user is a guest (not authenticated).
     * 
     * @return boolean whether the current user is a guest.
     * @see getIdentity()
     */
    public function getIsGuest()
    {
        return $this->getIdentity() === null;
    }

    /**
     * This method is called before logging in a user.
     * The default implementation will trigger the [[EVENT_BEFORE_LOGIN]] event.
     * If you override this method, make sure you call the parent implementation
     * so that the event is triggered.
     * 
     * @param IdentityInterface $identity
     *            the user identity information
     * @param boolean $cookieBased
     *            whether the login is cookie-based
     * @param integer $duration
     *            number of seconds that the user can remain in logged-in status.
     *            If 0, it means login till the user closes the browser or the session is manually destroyed.
     * @return boolean whether the user should continue to be logged in
     */
    protected function beforeLogin($identity, $cookieBased, $duration)
    {
        $event = new UserEvent([
            'identity' => $identity,
            'cookieBased' => $cookieBased,
            'duration' => $duration
        ]);
        $this->trigger(self::EVENT_BEFORE_LOGIN, $event);
        
        return $event->isValid;
    }

    /**
     * This method is called after the user is successfully logged in.
     * The default implementation will trigger the [[EVENT_AFTER_LOGIN]] event.
     * If you override this method, make sure you call the parent implementation
     * so that the event is triggered.
     * 
     * @param IdentityInterface $identity
     *            the user identity information
     * @param boolean $cookieBased
     *            whether the login is cookie-based
     * @param integer $duration
     *            number of seconds that the user can remain in logged-in status.
     *            If 0, it means login till the user closes the browser or the session is manually destroyed.
     */
    protected function afterLogin($identity, $cookieBased, $duration)
    {
        $this->trigger(self::EVENT_AFTER_LOGIN, new UserEvent([
            'identity' => $identity,
            'cookieBased' => $cookieBased,
            'duration' => $duration
        ]));
    }

    /**
     * This method is invoked when calling [[logout()]] to log out a user.
     * The default implementation will trigger the [[EVENT_BEFORE_LOGOUT]] event.
     * If you override this method, make sure you call the parent implementation
     * so that the event is triggered.
     * 
     * @param IdentityInterface $identity
     *            the user identity information
     * @return boolean whether the user should continue to be logged out
     */
    protected function beforeLogout($identity)
    {
        $event = new UserEvent([
            'identity' => $identity
        ]);
        $this->trigger(self::EVENT_BEFORE_LOGOUT, $event);
        
        return $event->isValid;
    }

    /**
     * This method is invoked right after a user is logged out via [[logout()]].
     * The default implementation will trigger the [[EVENT_AFTER_LOGOUT]] event.
     * If you override this method, make sure you call the parent implementation
     * so that the event is triggered.
     * 
     * @param IdentityInterface $identity
     *            the user identity information
     */
    protected function afterLogout($identity)
    {
        $this->trigger(self::EVENT_AFTER_LOGOUT, new UserEvent([
            'identity' => $identity
        ]));
    }

    /**
     * Renews the identity cookie.
     * This method will set the expiration time of the identity cookie to be the current time
     * plus the originally specified cookie duration.
     */
    protected function renewIdentityCookie()
    {
        $name = $this->identityCookie['name'];
        $value = Kant::$app->getRequest()->cookie($name);
        if ($value !== null) {
            $data = json_decode($value, true);
            if (is_array($data) && isset($data[2])) {
                $cookie = Kant::$app->getCookie()->make($name, $value, (int) $data[2]);
                Kant::$app->getResponse()->withCookie($cookie);
            }
        }
    }

    /**
     * Sends an identity cookie.
     * This method is used when [[enableAutoLogin]] is true.
     * It saves [[id]], [[IdentityInterface::getAuthKey()|auth key]], and the duration of cookie-based login
     * information in the cookie.
     * 
     * @param IdentityInterface $identity            
     * @param integer $duration
     *            number of seconds that the user can remain in logged-in status.
     * @see loginByCookie()
     */
    protected function sendIdentityCookie($identity, $duration)
    {
        $name = $this->identityCookie['name'];
        $cookie = Kant::$app->getCookie()->make($name, json_encode([
            $identity->getId(),
            $identity->getAuthKey(),
            $duration
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $duration);
        
        Kant::$app->getResponse()->withCookie($cookie);
    }

    /**
     * Determines if an identity cookie has a valid format and contains a valid auth key.
     * This method is used when [[enableAutoLogin]] is true.
     * This method attempts to authenticate a user using the information in the identity cookie.
     * 
     * @return array|null Returns an array of 'identity' and 'duration' if valid, otherwise null.
     * @see loginByCookie()
     * @since 2.0.9
     */
    protected function getIdentityAndDurationFromCookie()
    {
        $value = Kant::$app->getRequest()->cookie($this->identityCookie['name']);
        if ($value === null) {
            return null;
        }
        $data = json_decode($value, true);
        if (count($data) == 3) {
            list ($id, $authKey, $duration) = $data;
            /* @var $class IdentityInterface */
            $class = $this->identityClass;
            $identity = $class::findIdentity($id);
            if ($identity !== null) {
                if (! $identity instanceof IdentityInterface) {
                    throw new InvalidValueException("$class::findIdentity() must return an object implementing IdentityInterface.");
                } elseif (! $identity->validateAuthKey($authKey)) {
                    Kant::warning("Invalid auth key attempted for user '$id': $authKey", __METHOD__);
                } else {
                    return [
                        'identity' => $identity,
                        'duration' => $duration
                    ];
                }
            }
        }
        $this->removeIdentityCookie();
        return null;
    }

    /**
     * Removes the identity cookie.
     * This method is used when [[enableAutoLogin]] is true.
     * 
     * @since 2.0.9
     */
    protected function removeIdentityCookie()
    {
        $name = $this->identityCookie['name'];
        $cookie = Kant::$app->getCookie()->forget($name);
        Kant::$app->getResponse()->withCookie($cookie);
    }

    /**
     * Switches to a new identity for the current user.
     *
     * When [[enableSession]] is true, this method may use session and/or cookie to store the user identity information,
     * according to the value of `$duration`. Please refer to [[login()]] for more details.
     *
     * This method is mainly called by [[login()]], [[logout()]] and [[loginByCookie()]]
     * when the current user needs to be associated with the corresponding identity information.
     *
     * @param IdentityInterface|null $identity
     *            the identity information to be associated with the current user.
     *            If null, it means switching the current user to be a guest.
     * @param integer $duration
     *            number of seconds that the user can remain in logged-in status.
     *            This parameter is used only when `$identity` is not null.
     */
    public function switchIdentity($identity, $duration = 0)
    {
        $this->setIdentity($identity);
        
        if (! $this->enableSession) {
            return;
        }
        
        /* Ensure any existing identity cookies are removed. */
        if ($this->enableAutoLogin) {
            $this->removeIdentityCookie();
        }
        
        $session = Kant::$app->getSession();
        
        $session->remove($this->idParam);
        $session->remove($this->authTimeoutParam);
        
        if ($identity) {
            $session->set($this->idParam, $identity->getId());
            if ($this->authTimeout !== null) {
                $session->set($this->authTimeoutParam, time() + $this->authTimeout);
            }
            if ($this->absoluteAuthTimeout !== null) {
                $session->set($this->absoluteAuthTimeoutParam, time() + $this->absoluteAuthTimeout);
            }
            $session->save();
            if ($duration > 0 && $this->enableAutoLogin) {
                $this->sendIdentityCookie($identity, $duration);
            }
        }
    }

    /**
     * Updates the authentication status using the information from session and cookie.
     *
     * This method will try to determine the user identity using the [[idParam]] session variable.
     *
     * If [[authTimeout]] is set, this method will refresh the timer.
     *
     * If the user identity cannot be determined by session, this method will try to [[loginByCookie()|login by cookie]]
     * if [[enableAutoLogin]] is true.
     */
    protected function renewAuthStatus()
    {
        $session = Kant::$app->getSession();
        $id = $session->getId() ? $session->get($this->idParam) : null;
        
        if ($id === null) {
            $identity = null;
        } else {
            /* @var $class IdentityInterface */
            $class = $this->identityClass;
            $identity = $class::findIdentity($id);
        }
        
        $this->setIdentity($identity);
        
        if ($identity !== null && ($this->authTimeout !== null || $this->absoluteAuthTimeout !== null)) {
            $expire = $this->authTimeout !== null ? $session->get($this->authTimeoutParam) : null;
            $expireAbsolute = $this->absoluteAuthTimeout !== null ? $session->get($this->absoluteAuthTimeoutParam) : null;
            if ($expire !== null && $expire < time() || $expireAbsolute !== null && $expireAbsolute < time()) {
                $this->logout(false);
            } elseif ($this->authTimeout !== null) {
                $session->set($this->authTimeoutParam, time() + $this->authTimeout);
            }
        }
        
        if ($this->enableAutoLogin) {
            if ($this->getIsGuest()) {
                $this->loginByCookie();
            } elseif ($this->autoRenewCookie) {
                $this->renewIdentityCookie();
            }
        }
    }
}
