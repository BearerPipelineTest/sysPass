<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      http://syspass.org
 * @copyright 2012-2017, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP;

use Defuse\Crypto\Exception\CryptoException;
use DI\Container;
use Interop\Container\ContainerInterface;
use Klein\Klein;
use PHPMailer\PHPMailer\Exception;
use RuntimeException;
use SP\Config\Config;
use SP\Config\ConfigData;
use SP\Config\ConfigUtil;
use SP\Core\Crypt\CryptSessionHandler;
use SP\Core\Crypt\SecureKeyCookie;
use SP\Core\Crypt\Session as CryptSession;
use SP\Core\Exceptions\ConfigException;
use SP\Core\Exceptions\InitializationException;
use SP\Core\Exceptions\SPException;
use SP\Core\Language;
use SP\Core\Plugin\PluginUtil;
use SP\Core\Session\Session;
use SP\Core\SessionFactory;
use SP\Core\SessionUtil;
use SP\Core\Traits\InjectableTrait;
use SP\Core\UI\Theme;
use SP\Core\Upgrade\Upgrade;
use SP\Http\JsonResponse;
use SP\Http\Request;
use SP\Log\Email;
use SP\Log\Log;
use SP\Modules\Web\Controllers\MainController;
use SP\Mvc\View\Template;
use SP\Providers\Auth\Browser\Browser;
use SP\Storage\Database;
use SP\Storage\DBUtil;
use SP\Util\Checks;
use SP\Util\HttpUtil;
use SP\Util\Json;
use SP\Util\Util;

defined('APP_ROOT') || die();

/**
 * Class Bootstrap
 *
 * @package SP
 */
class Bootstrap
{
    use InjectableTrait;

    /**
     * Partial initialized controllers
     */
    const PARTIAL_INIT = ['resource', 'install', 'bootstrap'];

    /**
     * @var string The installation path on the server (e.g. /srv/www/syspass)
     */
    public static $SERVERROOT = '';
    /**
     * @var string The current request path relative to the sysPass root (e.g. files/index.php)
     */
    public static $WEBROOT = '';
    /**
     * @var string The full URL to reach sysPass (e.g. https://sub.example.com/syspass/)
     */
    public static $WEBURI = '';
    /**
     * @var bool True if sysPass has been updated. Only for notices.
     */
    public static $UPDATED = false;
    /**
     * @var int
     */
    public static $LOCK = 0;
    /**
     * @var ContainerInterface|Container
     */
    protected static $container;
    /**
     * @var string
     */
    private static $SUBURI = '';
    /**
     * @var bool Indica si la versión de PHP es correcta
     */
    private static $checkPhpVersion;
    /**
     * @var string
     */
    private static $sourceScript;
    /**
     * @var Upgrade
     */
    protected $upgrade;
    /**
     * @var Session
     */
    protected $session;
    /**
     * @var Theme
     */
    protected $theme;
    /**
     * @var Klein
     */
    protected $router;
    /**
     * @var Language
     */
    protected $language;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var ConfigData
     */
    private $configData;

    /**
     * Bootstrap constructor.
     *
     * @param Container $container
     * @throws Core\Dic\ContainerException
     */
    private final function __construct(Container $container)
    {
        self::$container = $container;

        $this->injectDependencies();

        $this->initRouter();
    }

    /**
     * Inicializar router
     */
    protected function initRouter()
    {
        $oops = "Oops, it looks like this content doesn't exist...";

        // Update request when we have a subdirectory
//        $_SERVER['REQUEST_URI'] = self::$WEBROOT;

        $this->router->onError(function ($router, $err_msg, $type, $err) {
            debugLog('Routing error: ' . $err_msg);

            /** @var Exception|\Throwable $err */
            debugLog('Routing error: ' . formatTrace($err->getTrace()));

            /** @var Klein $router */
            $router->response()->body($err_msg);
        });

        $self = $this;

        // Manejar URLs con módulo indicado
        $this->router->respond(['GET', 'POST'],
            '@/(index\.php)?',
            function ($request, $response, $service) use ($self, $oops) {
                try {
                    /** @var \Klein\Request $request */
                    $route = filter_var($request->param('r', 'index/index'), FILTER_SANITIZE_STRING);

                    if (!preg_match_all('#(?P<controller>[a-zA-Z]+)(?:/(?P<action>[a-zA-Z]+))?(?P<params>(/[a-zA-Z\d]+)+)?#', $route, $components)) {
                        throw new RuntimeException($oops);
                    }

                    $controller = $components['controller'][0];
                    $method = !empty($components['action'][0]) ? $components['action'][0] . 'Action' : 'indexAction';
                    $params = [];

                    if (!empty($components['params'][0])) {
                        foreach (explode('/', $components['params'][0]) as $value) {
                            if (is_numeric($value)) {
                                $params[] = (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
                            } elseif (!empty($value)) {
                                $params[] = filter_var($value, FILTER_SANITIZE_STRING);
                            }
                        }
                    }

                    $controllerClass = 'SP\\Modules\\' . ucfirst(APP_MODULE) . '\\Controllers\\' . ucfirst($controller) . 'Controller';

                    if (!method_exists($controllerClass, $method)) {
                        throw new RuntimeException($oops);
                    }

                    $self->initialize(in_array($controller, self::PARTIAL_INIT, true));

                    debugLog('Routing call: ' . $controllerClass . '::' . $method . '::' . print_r($params, true));

                    return call_user_func_array([new $controllerClass($method), $method], $params);
                } catch (\Exception $e) {
                    debugLog($e->getMessage(), true);

                    return $e->getMessage();
                }
            }
        );

        // Manejar URLs que no empiecen por '/admin'
//        $this->Router->respond('GET', '!@^/(admin|public|service)',
//            function ($request, $response) {
//                /** @var Response $response */
//                $response->redirect('index.php');
//            }
//        );
    }

    /**
     * Inicializar la aplicación
     *
     * @param bool $isPartial Do not perform a full initialization
     * @throws ConfigException
     * @throws InitializationException
     * @throws SPException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function initialize($isPartial = false)
    {
        debugLog('Initializing...');

        self::$checkPhpVersion = Checks::checkPhpVersion();

        // Inicializar autentificación
        $this->initAuthVariables();

        // Inicializar logging
        $this->initPHPVars();

        //  Establecer las rutas de la aplicación
        $this->initPaths();

        // Cargar las extensiones
//        self::loadExtensions();

        // Establecer el lenguaje por defecto
        $this->language->setLocales('en_US');

        if (!self::$checkPhpVersion) {
            throw new InitializationException(
                sprintf(__u('Versión de PHP requerida >= %s <= %s'), '5.6', '7.0'),
                SPException::ERROR,
                __u('Actualice la versión de PHP para que la aplicación funcione correctamente')
            );
        }

        // Comprobar la configuración
        $this->initConfig();

        // Iniciar la sesión de PHP
        $this->initSession($this->configData->isEncryptSession());

        // Volver a cargar la configuración si se recarga la página
        if (!Request::checkReload()) {
            // Cargar la configuración
            $this->config->loadConfig();

            // Cargar el lenguaje
            $this->language->setLanguage();
        } else {
            // Cargar la configuración
            $this->config->loadConfig(true);

            // Restablecer el idioma y el tema visual
            $this->language->setLanguage(true);
            $this->theme->initTheme(true);

            if ($this->session->isLoggedIn()) {
                // Recargar los permisos del perfil de usuario
//                $this->session->setUserProfile(Profile::getItem()->getById($this->session->getUserData()->getUserProfileId()));
                // Reset de los datos de ACL de cuentas
                $this->session->resetAccountAcl();
            }
        }

        // Comprobar si es necesario cambiar a HTTPS
        HttpUtil::checkHttps();

        if ($isPartial === false) {

            // Comprobar si está instalado
            $this->checkInstalled();

            // Comprobar si el modo mantenimiento está activado
            $this->checkMaintenanceMode();

            try {
                // Comprobar si la Base de datos existe
                DBUtil::checkDatabaseExist(self::$container->get(Database::class)->getDbHandler(), $this->configData->getDbName());
            } catch (\Exception $e) {
                if ($e->getCode() === 1049) {
                    $this->router->response()->redirect('index.php?r=install/index')->send();
                }
            }

            // Comprobar si es necesario actualizar componentes
//        $this->checkUpgrade();

            // Inicializar la sesión
            $this->initUserSession();

            // Cargar los plugins
            PluginUtil::loadPlugins();

            // Comprobar acciones en URL
//        $this->checkPreLoginActions();

            if ($this->session->isLoggedIn() && $this->session->getAuthCompleted() === true) {
                $browser = new Browser();

                // Comprobar si se ha identificado mediante el servidor web y el usuario coincide
                if ($browser->checkServerAuthUser($this->session->getUserData()->getLogin()) === false
                    && $browser->checkServerAuthUser($this->session->getUserData()->getSsoLogin()) === false
                ) {
                    throw new InitializationException('Logout');
//                $this->goLogout();
                }
            }
        }
    }

    /**
     * Establecer variables de autentificación
     */
    private function initAuthVariables()
    {
        $server = $this->router->request()->server();

        // Copiar la cabecera http de autentificación para apache+php-fcgid
        if ($server->get('HTTP_XAUTHORIZATION') !== null
            && $server->get('HTTP_AUTHORIZATION') === null) {
            $server->set('HTTP_AUTHORIZATION', $server->get('HTTP_XAUTHORIZATION'));
        }

        // Establecer las cabeceras de autentificación para apache+php-cgi
        // Establecer las cabeceras de autentificación para que apache+php-cgi funcione si la variable es renombrada por apache
        if (($server->get('HTTP_AUTHORIZATION') !== null
                && preg_match('/Basic\s+(.*)$/i', $server->get('HTTP_AUTHORIZATION'), $matches))
            || ($server->get('REDIRECT_HTTP_AUTHORIZATION') !== null
                && preg_match('/Basic\s+(.*)$/i', $server->get('REDIRECT_HTTP_AUTHORIZATION'), $matches))
        ) {
            list($name, $password) = explode(':', base64_decode($matches[1]), 2);
            $server->set('PHP_AUTH_USER', strip_tags($name));
            $server->set('PHP_AUTH_PW', strip_tags($password));
        }
    }

    /**
     * Establecer el nivel de logging
     */
    public function initPHPVars()
    {
        // Establecer el modo debug si una sesión de xdebug está activa
        if (isset($_COOKIE['XDEBUG_SESSION']) && !defined('DEBUG')) {
            define('DEBUG', true);
        }

        if (defined('DEBUG') && DEBUG) {
            error_reporting(E_ALL);
            ini_set('display_errors', 'On');
        } else {
            error_reporting(E_ALL & ~(E_DEPRECATED | E_STRICT | E_NOTICE));
            ini_set('display_errors', 'Off');
        }

        if (!file_exists(LOG_FILE) && touch(LOG_FILE) && chmod(LOG_FILE, 0600)) {
            debugLog('Setup log file: ' . LOG_FILE);
        }

        if (date_default_timezone_get() === 'UTC') {
            date_default_timezone_set('UTC');
        }

        // Evita que javascript acceda a las cookies de sesion de PHP
        ini_set('session.cookie_httponly', '1');
        ini_set('session.save_handler', 'files');
    }

    /**
     * Establecer las rutas de la aplicación.
     * Esta función establece las rutas del sistema de archivos y web de la aplicación.
     * La variables de clase definidas son $SERVERROOT, $WEBROOT y $SUBURI
     */
    private function initPaths()
    {
        self::$SERVERROOT = dirname(BASE_PATH);

        self::$SUBURI = str_replace("\\", '/', substr(realpath($_SERVER['SCRIPT_FILENAME']), strlen(self::$SERVERROOT)));

        $scriptName = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        if (substr($scriptName, -1) === '/') {
            $scriptName .= 'index.php';
            // Asegurar que suburi sigue las mismas reglas que scriptName
            if (substr(self::$SUBURI, -9) !== 'index.php') {
                if (substr(self::$SUBURI, -1) !== '/') {
                    self::$SUBURI .= '/';
                }
                self::$SUBURI .= 'index.php';
            }
        }

        if (($pos = strpos($scriptName, self::$SUBURI)) === false) {
            $pos = strpos($scriptName, '?');
        }

        self::$WEBROOT = substr($scriptName, 0, $pos);

        if (self::$WEBROOT !== '' && self::$WEBROOT[0] !== '/') {
            self::$WEBROOT = '/' . self::$WEBROOT;
        }

        self::$WEBURI = HttpUtil::getHttpHost() . self::$WEBROOT;
    }

    /**
     * Cargar la configuración
     *
     * @throws ConfigException
     */
    private function initConfig()
    {
        // Comprobar si es una versión antigua
        $this->checkConfigVersion();

        ConfigUtil::checkConfigDir();
    }

    /**
     * Comprobar la versión de configuración y actualizarla
     */
    private function checkConfigVersion()
    {
        $appVersion = Util::getVersionStringNormalized();

        if (file_exists(OLD_CONFIG_FILE)
            && $this->upgrade->upgradeOldConfigFile($appVersion)
        ) {
//            $this->logConfigUpgrade($appVersion);

            self::$UPDATED = true;

            return;
        }

        $configVersion = Upgrade::fixVersionNumber($this->configData->getConfigVersion());

        if ($this->configData->isInstalled()
            && Util::checkVersion($configVersion, Util::getVersionArrayNormalized())
            && $this->upgrade->needConfigUpgrade($configVersion)
            && $this->upgrade->upgradeConfig($configVersion)
        ) {
//            $this->logConfigUpgrade($appVersion);

            self::$UPDATED = true;
        }
    }

    /**
     * Iniciar la sesión PHP
     *
     * @param bool $encrypt Encriptar la sesión de PHP
     * @throws InitializationException
     */
    private function initSession($encrypt = false)
    {
        if ($encrypt === true) {
            if (($key = SecureKeyCookie::getKey()) !== false && self::$checkPhpVersion) {
                session_set_save_handler(new CryptSessionHandler($key), true);
            }
        }

        // Si la sesión no puede ser iniciada, devolver un error 500
        if (session_start() === false) {
            $this->router->response()->header('HTTP/1.1', '500 Internal Server Error');

            throw new InitializationException(__u('La sesión no puede ser inicializada'));
        }
    }

    /**
     * Comprueba que la aplicación esté instalada
     * Esta función comprueba si la aplicación está instalada. Si no lo está, redirige al instalador.
     *
     * @throws InitializationException
     */
    private function checkInstalled()
    {
        // Redirigir al instalador si no está instalada
        if (!$this->configData->isInstalled()
            && $this->router->request()->param('r') !== 'install/index'
        ) {
            $this->router->response()->redirect('index.php?r=install/index')->send();

            throw new InitializationException('Not installed');

//            if (self::$SUBURI !== '/index.php') {
//                // FIXME
//                $this->router->response()->redirect('index.php?r=install');
//
//                $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
//
//                $url = $protocol . $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . self::$WEBROOT . '/index.php';
//                header("Location: $url");
//                exit();
//            }
//
//            if ($this->session->getAuthCompleted()) {
//                session_destroy();
//
//                $this->initialize();
//                return;
//            }
//
//            // Comprobar si sysPass está instalada o en modo mantenimiento
//            $Controller = new MainController();
//            $Controller->getInstaller();
//            $Controller->view();
//            exit();
        }
    }

    /**
     * Comprobar si el modo mantenimiento está activado
     * Esta función comprueba si el modo mantenimiento está activado.
     * Devuelve un error 503 y un reintento de 120s al cliente.
     *
     * @param bool $check sólo comprobar si está activado el modo
     * @return bool
     * @throws InitializationException
     */
    public function checkMaintenanceMode($check = false)
    {
        if ($this->configData->isMaintenance()) {
            self::$LOCK = Util::getAppLock();

            if ($check === true
                || Checks::isAjax($this->router)
                || Request::analyze('nodbupgrade', 0) === 1
                || (Request::analyze('a') === 'upgrade' && Request::analyze('type') !== '')
                || (self::$LOCK > 0 && $this->session->isLoggedIn() && self::$LOCK === $this->session->getUserData()->getId())
            ) {
                return true;
            }

            throw new InitializationException(__u('Aplicación en mantenimiento'), SPException::INFO, __u('En breve estará operativa'));
        }
    }

    /**
     * Inicializar la sesión de usuario
     *
     */
    private function initUserSession()
    {
        $lastActivity = $this->session->getLastActivity();
        $inMaintenance = $this->configData->isMaintenance();

        // Timeout de sesión
        if ($lastActivity > 0
            && !$inMaintenance
            && (time() - $lastActivity) > $this->getSessionLifeTime()
        ) {
            if ($this->router->request()->cookies()->get(session_name()) !== null) {
                $this->router->response()->cookie(session_name(), '', time() - 42000);
            }

//            $this->wrLogoutInfo();

            SessionUtil::restart();
            return;
        }

        $sidStartTime = $this->session->getSidStartTime();

        // Regenerar el Id de sesión periódicamente para evitar fijación
        if ($sidStartTime === 0) {
            // Intentar establecer el tiempo de vida de la sesión en PHP
            @ini_set('session.gc_maxlifetime', $this->getSessionLifeTime());

            $this->session->setSidStartTime(time());
            $this->session->setStartActivity(time());
        } else if (!$inMaintenance
            && time() - $sidStartTime > 120
            && $this->session->getUserData()->getId() > 0
        ) {
            try {
                CryptSession::reKey();

                // Recargar los permisos del perfil de usuario
//                $this->session->setUserProfile(Profile::getItem()->getById($this->session->getUserData()->getUserProfileId()));
            } catch (CryptoException $e) {
                debugLog($e->getMessage());

                SessionUtil::restart();
                return;
            }
        }

        $this->session->setLastActivity(time());
    }

    /**
     * Obtener el timeout de sesión desde la configuración.
     *
     * @return int con el tiempo en segundos
     */
    private function getSessionLifeTime()
    {
        $timeout = $this->session->getSessionTimeout();

        if (null === $timeout) {
            $configTimeout = $this->configData->getSessionTimeout();
            $this->session->setSessionTimeout($configTimeout);

            return $configTimeout;
        }

        return $timeout;
    }

    /**
     * @return ContainerInterface
     */
    public static function getContainer()
    {
        return self::$container;
    }

    /**
     * Devuelve un error utilizando la plantilla de error o en formato JSON
     *
     * @param string $message con la descripción del error
     * @param string $hint opcional, con una ayuda sobre el error
     * @param bool $headers
     */
    public static function initError($message, $hint = '', $headers = false)
    {
        debugLog(__FUNCTION__);
        debugLog(__($message));
        debugLog(__($hint));

        if (Checks::isJson()) {
            $JsonResponse = new JsonResponse();
            $JsonResponse->setDescription($message);
            $JsonResponse->addMessage($hint);
            Json::returnJson($JsonResponse);
        } elseif ($headers === true) {
            header('HTTP/1.1 503 Service Temporarily Unavailable');
            header('Status: 503 Service Temporarily Unavailable');
            header('Retry-After: 120');
        }

        SessionUtil::cleanSession();

        $Tpl = new Template();
        $Tpl->append('errors', ['type' => SPException::CRITICAL, 'description' => __($message), 'hint' => __($hint)]);

        $Controller = new MainController($Tpl, 'error', !Checks::isAjax());
        $Controller->getError();
    }

    /**
     * @param Container $container
     * @param string $module
     * @throws Core\Dic\ContainerException
     * @throws InitializationException
     */
    public static function run(Container $container, $module = APP_MODULE)
    {
        $bs = new static($container);

        switch ($module) {
            case 'web':
                $bs->router->dispatch();
                break;
            default;
                throw new InitializationException('Unknown module');
        }
    }

    /**
     * Comprobar si hay que ejecutar acciones de URL después de realizar login.
     *
     * @return bool
     */
    public function checkPostLoginActions()
    {
        $action = Request::analyze('a');

        if ($action === '') {
            return false;
        }

        $Controller = new MainController();
        $Controller->doAction('postlogin.' . $action);

        return false;
    }

    /**
     * @param Config $config
     * @param Upgrade $upgrade
     * @param Session $session
     * @param Theme $theme
     * @param Klein $router
     * @param Language $language
     */
    public function inject(Config $config,
                           Upgrade $upgrade,
                           Session $session,
                           Theme $theme,
                           Klein $router,
                           Language $language
    )
    {
        $this->config = $config;
        $this->configData = $config->getConfigData();
        $this->upgrade = $upgrade;
        $this->session = $session;
        $this->theme = $theme;
        $this->router = $router;
        $this->language = $language;
    }

    /**
     * Comprobar si hay que ejecutar acciones de URL antes de presentar la pantalla de login.
     *
     * @return bool
     */
    public function checkPreLoginActions()
    {
        $action = Request::analyze('a');

        if ($action === '') {
            return false;
        }

        $Controller = new MainController();
        $Controller->doAction('prelogin.' . $action);

        return true;
    }

    /**
     * Comprobar el archivo que realiza el include necesita inicialización.
     *
     * @deprecated
     * @returns bool
     */
    private function checkInitSourceInclude()
    {
        self::$sourceScript = pathinfo($_SERVER['SCRIPT_NAME'], PATHINFO_BASENAME);
        $skipInit = ['js.php', 'css.php', 'api.php', 'ajax_getEnvironment.php', 'ajax_task.php'];

        return in_array(self::$sourceScript, $skipInit, true);
    }

    /**
     * Comprobar si es necesario actualizar componentes
     *
     * @throws \Defuse\Crypto\Exception\EnvironmentIsBrokenException
     */
    private function checkUpgrade()
    {
        if (self::$SUBURI === '/index.php') {
            $this->upgrade->checkDbVersion();
            $this->upgrade->checkAppVersion();
        }
    }

    /**
     * Registrar la actualización de la configuración
     *
     * @deprecated
     * @param $version
     */
    private function logConfigUpgrade($version)
    {
        $Log = new Log();
        $LogMessage = $Log->getLogMessage();
        $LogMessage->setAction(__('Actualización', false));
        $LogMessage->addDescription(__('Actualización de versión realizada.', false));
        $LogMessage->addDetails(__('Versión', false), $version);
        $LogMessage->addDetails(__('Tipo', false), 'config');
        $Log->writeLog();

        Email::sendEmail($LogMessage);
    }

    /**
     * Comprobar si es necesario cerrar la sesión
     */
    private function checkLogout()
    {
        if (Request::analyze('logout', false, true)) {
            $this->goLogout();
        }
    }

    /**
     * Deslogar el usuario actual y eliminar la información de sesión.
     *
     * @deprecated
     */
    private function goLogout()
    {
        $this->wrLogoutInfo();

        SessionUtil::cleanSession();

        SessionFactory::setLoggedOut(true);

        $Controller = new MainController();
        $Controller->getLogout();
    }

    /**
     * Escribir la información de logout en el registro de eventos.
     *
     * @deprecated
     */
    private function wrLogoutInfo()
    {
        $inactiveTime = abs(round((time() - $this->session->getLastActivity()) / 60, 2));
        $totalTime = abs(round((time() - $this->session->getStartActivity()) / 60, 2));

        $Log = new Log();
        $LogMessage = $Log->getLogMessage();
        $LogMessage->setAction(__('Finalizar sesión', false));
        $LogMessage->addDetails(__('Usuario', false), $this->session->getUserData()->getLogin());
        $LogMessage->addDetails(__('Tiempo inactivo', false), $inactiveTime . ' min.');
        $LogMessage->addDetails(__('Tiempo total', false), $totalTime . ' min.');
        $Log->writeLog();
    }
}