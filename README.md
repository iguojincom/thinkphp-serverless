将Thinkphp5.1无差别运行在serverless环境
===============

## 腾讯云

入口文件
~~~
tencent.php
~~~

-  serverless cli
-  执行 `npm install` 安装依赖
-  执行 `sls deploy` 部署函数

### 注意事项

-  serverless代码目录不可写,需要修改cache,log等配置的写入path到/tmp目录

### 已知问题

-  html页面时,trace方法打印的数据无法显示在页面右下角的工具里
-  最好使用腾讯云serverless组件部署,暂时用的serverless cli

### todo
-  近期改成使用composer进行安装