<?php

namespace app\index\controller;

use think\facade\Config;

class Index
{
    public static $sum;

    public function __construct()
    {
        static::$sum++;
    }

    public function index()
    {
        cache('1', 2);

        if (request()->file('binary')) {
            return '文件大小:' . request()->file('binary')->getSize();
        }
        return '<h1>' . date('Y-m-d H:i:s') . '</h1>';
    }

    public function Test()
    {
        echo request()->action();
    }

    public function hello($name = 'ThinkPHP5')
    {
        return 'hello:' . $name;
    }

    public function agent()
    {
        return request()->header('user-agent');
    }

    public function show()
    {
        return static::$sum;
    }
}
