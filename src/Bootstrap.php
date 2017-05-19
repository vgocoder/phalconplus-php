<?php

namespace PhalconPlus;


final class Bootstrap
{

    const COMMON_DIR_NAME = "common";


    const COMMON_CONF_DIR_NAME = "config";


    const COMMON_LOAD_DIR_NAME = "load";


    const ROOT_PUB_DIR_NAME = "public";


    const MODULE_APP_DIR_NAME = "app";


    const DS = "/";


    const PHP_EXT = ".php";


    const ENV_NAME = "phalconplus.env";


    protected $config;


    protected $di;


    protected $application;


    protected $module = ["classPath" => "", "className" => "", "mode" => ""];


    protected $modeMap = ["Web" => "Module", "Cli" => "Task", "Srv" => "Srv", "Micro" => "Micro"];


    protected $env = "dev";


    /**
     * @param string $modulePath
     */
    public function __construct($modulePath)
    {
        // 模块目录
        if (file_exists($modulePath)) {
            throw new \Exception("Module directory not exists or not a dir, file positon: " . $modulePath);
        }

        // 获取并初始化运行环境
        $env = get_cfg_var(self::ENV_NAME);
        if (!empty($env)) {
            $this->env = $env;
        }
        // 如果不是生产环境，打开Debug
        if (substr(PHP_SAPI, 0, 3) != "cli" && substr($this->env, 0, 7) != "product") {
            $debug = new \Phalcon\Debug();
            $debug->listen();
        }

        // 定义全局常量
        define("APP_ENV", $this->env, true);
        define("APP_MODULE_DIR", rtrim($modulePath, "/") . "/", true);
        define("APP_ROOT_DIR", rtrim(dirname($modulePath), "/") . "/", true);
        define("APP_ROOT_COMMON_DIR", APP_ROOT_DIR . self::COMMON_DIR_NAME . "/", true);
        define("APP_ROOT_COMMON_CONF_DIR", APP_ROOT_COMMON_DIR . self::COMMON_CONF_DIR_NAME . "/", true);
        define("APP_ROOT_COMMON_LOAD_DIR", APP_ROOT_COMMON_DIR . self::COMMON_LOAD_DIR_NAME . "/", true);
    }

    /**
     * @param array $module
     */
    private function setModule(array $module)
    {
        $diff = array_diff_key($this->module, $module);
        if (!empty($diff)) {
            throw new \Exception("Module is not a legal module, details: " . json_encode($module));
        }
        $this->module = $module;
    }


    public function initConf()
    {
        // 全局配置
        $globalConfPath = APP_ROOT_COMMON_CONF_DIR . "config" . self::PHP_EXT;
        if (!file_exists($globalConfPath)) {
            throw new \Phalcon\Config\Exception("Global config file not exist, file position: " . $globalConfPath);
        }
        $this->config = new \Phalcon\Config($this->load($globalConfPath));

        // 模块配置, 如果找不到"app/config/{APP_ENV}.php"，则去找"app/config/config.php"
        $moduleConfPath = APP_MODULE_DIR . "app/config/" . APP_ENV . self::PHP_EXT;
        if (!file_exists($moduleConfPath)) {
            $moduleConfPath = APP_MODULE_DIR . "app/config/config" . self::PHP_EXT;
            if (!file_exists($moduleConfPath)) {
                throw new \Phalcon\Config\Exception("Module config file not exist, file position: " . $moduleConfPath);
            }
        }

        $moduleConf = new \Phalcon\Config($this->load($moduleConfPath));

        // 初始化模块三要素
        $module = [];
        $module["mode"] = ucfirst(strtolower($moduleConf->application->mode));
        $module["className"] = $moduleConf->application->ns . $this->modeMap[$module["mode"]];
        $module["classPath"] = APP_MODULE_DIR . "app/" . $this->modeMap[$module["mode"]] . self::PHP_EXT;

        // 定义工作模式
        define("APP_RUN_MODE", $module["mode"], true);

        $this->setModule($module);

        // 合并配置，Module配置优先级更高
        $this->config->merge($moduleConf);
    }


    public function exec()
    {
        $this->initConf();
        $handleMethod = "exec" . $this->modeMap[APP_RUN_MODE];
        $params = func_get_args();
        return call_user_func_array([$this, $handleMethod], $params);
    }

    /**
     * @param mixed $uri
     * @param bool $needHandle
     */
    public function execModule($uri = null, $needHandle = true)
    {
        // 如果不需要handle，需要自己加载配置
        if (!$needHandle) {
            $this->initConf();
        }
        // 应用初始化
        // $this->loader = new \Phalcon\Loader();
        $this->di = new \Phalcon\DI\FactoryDefault();
        $this->application = new \Phalcon\Mvc\Application();
        $this->application->setDI($this->di);

        // 加载Web模式依赖
        $this->load(APP_ROOT_COMMON_LOAD_DIR . "default-web.php");
        // 把自己注入di
        $this->di->setShared("bootstrap", $this);
        // 包含模块化类
        require $this->module["classPath"];
        // 模块初始化类
        $moduleClass = $this->module["className"];
        // 实例化该类
        $module = new $moduleClass($this->di);
        // 如果不需要handle，则直接返回
        if (!$needHandle) {
            return true;
        }

        // 执行
        try {
            echo $this->application->handle($uri)->getContent();
        } catch (\Phalcon\Mvc\Application\Exception $e) {
            $router = $this->di->get("router");
            $newUri = "/" . $router->getDefaultModule() . $router->getRewriteUri();
            echo $this->application->handle($newUri)->getContent();
        }
    }

    /**
     * @param bool $needHandle
     */
    public function execSrv($needHandle = true)
    {
        if (!$needHandle) {
            $this->initConf();
        }
        // no need to get `loader` here
        // $this->loader = new \Phalcon\Loader();
        $this->di = new \Phalcon\DI\FactoryDefault();

        $this->di->setShared("bootstrap", $this);
        $this->load(APP_ROOT_COMMON_LOAD_DIR . "default-web.php");

        require $this->module["classPath"];
        $moduleClass = $this->module["className"];
        $moduleObj = new $moduleClass($this->di);

        if (!$needHandle) {
            return true;
        }

        $backendSrv = new \PhalconPlus\Base\BackendServer($this->di);
        $this->application = new \Yar_Server($backendSrv);

        $this->application->handle();
    }

    /**
     * @param array $argv
     * @param \Phalcon\DI\FactoryDefault $di
     * @param mixed $needHandle
     */
    public function execTask(array $argv, \Phalcon\DI\FactoryDefault $di = null, $needHandle = true)
    {

        $this->initConf();

        // no need to get `loader` here
        // $this->loader = new \Phalcon\Loader();

        if (is_null($di) || !($di instanceof \Phalcon\DI\FactoryDefault\CLI)) {
            $this->di = new \Phalcon\DI\FactoryDefault\CLI();
        } else {
            $this->di = $di;
        }

        $this->application = new \Phalcon\CLI\Console();
        $this->application->setDI($this->di);

        $this->load(APP_ROOT_COMMON_LOAD_DIR . "default-cli.php");
        $this->di->setShared("bootstrap", $this);

        // Load module
        require $this->module["classPath"];
        $moduleClass = $this->module["className"];
        $module = new $moduleClass($this->di);

        if (!$needHandle) {
            return true;
        }

        return $this->application->handle($argv);
    }

    /**
     * @param string $moduleName
     */
    public function dependModule($moduleName)
    {
        $moduleConfPath = APP_ROOT_DIR . $moduleName . "/app/config/" . APP_ENV . self::PHP_EXT;
        if (!file_exists($moduleConfPath)) {
            $moduleConfPath = APP_ROOT_DIR . $moduleName . "/app/config/config" . self::PHP_EXT;
            if (!file_exists($moduleConfPath)) {
                throw new \Phalcon\Config\Exception("Module config file not exist, file position: " . $moduleConfPath);
            }
        }
        $moduleConf = new \Phalcon\Config($this->load($moduleConfPath));
        $reservedModuleConf = new \Phalcon\Config($this->load($moduleConfPath));

        $moduleRunMode = $moduleConf->application->mode;

        // 获取模块类名
        $moduleClassName = $moduleConf->application->ns . $this->modeMap[$moduleRunMode];
        $moduleClassPath = APP_ROOT_DIR . $moduleName . "/app/" . $this->modeMap[$moduleRunMode] . self::PHP_EXT;
        if (!file_exists($moduleClassPath)) {
            throw new \Exception("Module init file not exists, file position: " . $moduleClassPath);
        }

        // 保留被依赖的模块的配置
        $this->di->set("moduleConfig", $reservedModuleConf);
        // 全局配置文件优先级高于被依赖的模块
        $moduleConf->merge($this->config);
        $this->setConfig($moduleConf);

        require $moduleClassPath;
        return new $moduleClassName($this->di);
    }

    /**
     * @return string
     */
    public function getEnv()
    {
        return $this->env;

    }

    /**
     * @return \Phalcon\Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param \Phalcon\Config $config
     */
    public function setConfig(\Phalcon\Config $config)
    {
        $globalConf = '';
        if (!$this->di->has("config")) {
            $globalConf = new \Phalcon\Config();
        } else {
            $globalConf = $this->di->getConfig();
        }
        $globalConf->merge($config);
        $this->config = $globalConf;
        $this->di->set("config", $this->config);
        return $this;
    }

    /**
     * @return \Phalcon\DI
     */
    public function getDI()
    {
        return $this->di;
    }

    /**
     * @param mixed $filePath
     */
    public function load($filePath)
    {
        if (!file_exists($filePath)) {
            throw new \Exception("The file you try to load is not exists. file position: " . $filePath);
        }
        extract(["rootPath" => APP_ROOT_DIR,
            "loader" => new \Phalcon\Loader(),
            "config" => $this->config,
            "application" => $this->application,
            "bootstrap" => $this,
            "di" => $this->di
        ]);
        return require $filePath;
    }

}
