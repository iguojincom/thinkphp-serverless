<?php
/**
 * thinkphp5.1运行在腾讯云serverless的容器
 *
 * 2020-03-03
 *
 * 范国金
 */
// 启动框架
require_once __DIR__ . '/thinkphp/base.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/multipart.php';

// 应用目录
define('APP_PATH', __DIR__ . '/application/');
// 缓存目录
define('RUNTIME_PATH', '/tmp/');
// 静态目录
define('STATIC_PATH', __DIR__ . '/public');
// 文本文件
define('TEXT_REG', '#\.html.*|\.js.*|\.css.*|\.html.*#');
// 其他文件
define('BINARY_REG', '#\.gif.*|\.jpg.*|\.png.*|\.jepg.*|\.swf.*|\.bmp.*|\.ico.*#');

use think\Container;
use think\Db;
use think\Error;
use think\exception\ClassNotFoundException;
use think\exception\HttpResponseException;
use think\facade\Request;
use think\Loader;
use think\Route;
use think\route\Dispatch;

class App extends \think\App
{
    public static function mainHandler($event, $context)
    {
        // 请求路径
        $path = str_replace("//", "/", $event->path);

        // 静态文件
        if (preg_match(TEXT_REG, $path) || preg_match(BINARY_REG, $path)) {
            return static::handlerStatic($path);
        }

        // 请求数据
        $req = $event->body ?? '';

        // 请求头部
        $headers = $event->headers ?? [];
        $headers = json_decode(json_encode($headers), true);
        $headers['HTTP_X_REAL_IP'] = $event->requestContext->sourceIp;
        $headers['HTTP_REMOTE_ADDR'] = $event->requestContext->sourceIp;

        // 启动框架
        Container::set('app', \App::class);
        $app = Container::get('app');
        $app->path(APP_PATH)->initialize();

        // 请求参数
        $query = $event->queryString ?? [];
        $query = json_decode(json_encode($query), true);
        $params = json_decode($event->body ?? '', true) ?? [];
        $params = array_merge($query, $params);

        // multipart
        $data = (new MultipartFormDataParser)->parse($headers, $event->body ?? '');
        $files = $data['files'] ?? [];
        $bodyParams = $data['params'] ?? [];
        $params = array_merge($params, $bodyParams);

        // 重置数据
        $app->setBeginTime(microtime(true));
        $app->setBeginMem(memory_get_usage());
        $app->delete('think\Request');
        Db::$queryTimes = 0;
        Db::$executeTimes = 0;

        // 请求内容
        $uri = $event->path . '?' . http_build_query($query);
        $app->request = Request::create($uri, $event->httpMethod, $params, $cookie = [], $files, $server = [], $event->body ?? '');
        $app->request->withHeader($headers);
        $app->route->setRequest($app->request);

        // 执行请求
        $resp = $app->run();
        $body = $resp->getContent();
        $status = $resp->getCode();
        $header = $resp->getHeader();

        // 调试注入
        if ($app->env->get('app_trace', $app->config->get('app_trace'))) {
            $app->debug->inject($resp, $body);
        }

        return [
            'isBase64Encoded' => false,
            'statusCode' => $status,
            'headers' => $header,
            'body' => $body,
        ];
    }

    public static function handlerStatic($path)
    {
        $filename = STATIC_PATH . $path;
        $handle = fopen($filename, "r");
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        $base64Encode = false;
        $headers = [
            'Content-Type' => '',
            'Accept-Ranges' => 'bytes',
        ];
        $body = $contents;
        if (preg_match(BINARY_REG, $path)) {
            $base64Encode = true;
            $headers = [
                'Content-Type' => '',
            ];
            $body = base64_encode($contents);
        }
        return [
            "isBase64Encoded" => $base64Encode,
            "statusCode" => 200,
            "headers" => $headers,
            "body" => $body,
        ];
    }

    public function setBeginTime($value)
    {
        $this->beginTime = $value;
        return $this;
    }

    public function setBeginMem($value)
    {
        $this->beginMem = $value;
        return $this;
    }

    /**
     * 初始化应用
     * @access public
     * @return void
     */
    public function initialize()
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        $this->beginTime = microtime(true);
        $this->beginMem = memory_get_usage();
        $this->rootPath = dirname($this->appPath) . DIRECTORY_SEPARATOR;
        // 修改写入路径
        $this->runtimePath = defined('RUNTIME_PATH') ? RUNTIME_PATH : $this->rootPath . 'runtime' . DIRECTORY_SEPARATOR;
        $this->routePath = $this->rootPath . 'route' . DIRECTORY_SEPARATOR;
        $this->configPath = $this->rootPath . 'config' . DIRECTORY_SEPARATOR;
        static::setInstance($this);
        $this->instance('app', $this);
        if (is_file($this->rootPath . '.env')) {
            $this->env->load($this->rootPath . '.env');
        }
        $this->configExt = $this->env->get('config_ext', '.php');
        $this->config->set(include $this->thinkPath . 'convention.php');
        $this->env->set([
            'think_path' => $this->thinkPath,
            'root_path' => $this->rootPath,
            'app_path' => $this->appPath,
            'config_path' => $this->configPath,
            'route_path' => $this->routePath,
            'runtime_path' => $this->runtimePath,
            'extend_path' => $this->rootPath . 'extend' . DIRECTORY_SEPARATOR,
            'vendor_path' => $this->rootPath . 'vendor' . DIRECTORY_SEPARATOR,
        ]);
        $this->namespace = $this->env->get('app_namespace', $this->namespace);
        $this->env->set('app_namespace', $this->namespace);
        Loader::addNamespace($this->namespace, $this->appPath);
        $this->init();
        $this->suffix = $this->config('app.class_suffix');
        $this->appDebug = $this->env->get('app_debug', $this->config('app.app_debug'));
        $this->env->set('app_debug', $this->appDebug);
        if (!$this->appDebug) {
            ini_set('display_errors', 'Off');
        } elseif (PHP_SAPI != 'cli') {
            if (ob_get_level() > 0) {
                $output = ob_get_clean();
            }
            ob_start();
            if (!empty($output)) {
                echo $output;
            }
        }
        if ($this->config('app.exception_handle')) {
            Error::setExceptionHandler($this->config('app.exception_handle'));
        }
        if (!empty($this->config('app.root_namespace'))) {
            Loader::addNamespace($this->config('app.root_namespace'));
        }
        Loader::loadComposerAutoloadFiles();
        Loader::addClassAlias($this->config->pull('alias'));
        Db::init($this->config->pull('database'));
        date_default_timezone_set($this->config('app.default_timezone'));
        $this->loadLangPack();
        $this->routeInit();
    }
}

// 入口函数
function main_handler($event, $context)
{
    return App::mainHandler($event, $context);
}
