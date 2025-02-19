<?php
/**
 * @Author: 陈静
 * @Date: 2018/04/17 10:24:33
 * @Description: API 模块配置
 */

return [

    // 默认输出类型
    'default_return_type'    => 'json',
    // 默认AJAX 数据返回格式,可选json xml ...
    'default_ajax_return'    => 'json',
    // 默认JSONP格式返回的处理方法
    'default_jsonp_handler'  => 'jsonpReturn',
    // 默认JSONP处理方法
    'var_jsonp_handler'      => 'callback',
    // 默认时区
    'default_timezone'       => 'PRC',
    //异常接管
    'exception_handle'       => 'app\common\library\exception\ApiHandleException',
    //控制器后缀
    'controller_suffix'      => 'Controller',

];