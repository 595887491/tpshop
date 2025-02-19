<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
//基础配置(tp5原始)
$base_config = [
    // 数据库类型
    'type'           => 'mysql',
    // 端口
    'hostport'       => '3306',
    // 连接dsn
    'dsn'            => '',
    // 数据库连接参数
    'params'         => [],
    // 数据库编码默认采用utf8
    'charset'        => 'utf8mb4',
    // 数据库表前缀
    'prefix'         => 'tp_',
    // 数据库调试模式
    'debug'          => true,
    // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
    'deploy'         => 0,
    // 数据库读写是否分离 主从式有效
    'rw_separate'    => false,
    // 读写分离后 主服务器数量
    'master_num'     => 1,
    // 指定从服务器序号
    'slave_no'       => '',
    // 是否严格检查字段是否存在
    'fields_strict'  => true,
    // 数据集返回类型 array 数组 collection Collection对象
    'resultset_type' => 'array',
    // 是否自动写入时间戳字段
    'auto_timestamp' => false,
    // 是否需要进行SQL性能分析
    'sql_explain'    => false,
];

//基于环境的配置
switch ($GLOBALS['ENV'])
{
    //个人数据库配置
    case 'CHENJING':
        $base_on_env_config = [
            // 服务器地址
            'hostname'       => '127.0.0.1',
            // 数据库名
            'database'       => 'tpshop',
            // 用户名
            'username'       => 'root',
            // 密码
            'password'       => 'root'
        ];
        break;
    //前端单独配置
    default:
    case 'LOCAL':
        $base_on_env_config = [
            // 服务器地址
            'hostname'       => '192.168.0.223',
            // 数据库名
            'database'       => 'tpshop_dev',
            // 用户名
            'username'       => 'root',
            // 密码
            'password'       => 'root'
        ];
        break;
    //默认设置
    case 'DEV':
        $base_on_env_config = [
            // 服务器地址
            'hostname'       => '127.0.0.1',
            // 数据库名
            'database'       => 'tpshop_dev',
            // 用户名
            'username'       => 'root',
            // 密码
            'password'       => 'shangmei&bf&0314'
        ];
        break;
        //默认设置
    case 'TEST':
        $base_on_env_config = [
            // 服务器地址
            'hostname'       => '127.0.0.1',
            // 数据库名
            'database'       => 'tpshop_test',
            // 用户名
            'username'       => 'root',
            // 密码
            'password'       => 'shangmei&bf&0314'
        ];
        break;
    //正式环境
    case 'FORMAL':
        $base_on_env_config = [
            // 服务器地址
            'hostname'       => '127.0.0.1',
            // 数据库名
            'database'       => 'tpshop_formal',
            // 用户名
            'username'       => 'root',
            // 密码
            'password'       => 'shangmei#binfen#0314g'
        ];
        break;
    //正式环境
    case 'PRODUCT':
        $base_on_env_config = [
            // 服务器地址
            'hostname'       => '127.0.0.1',
            // 数据库名
            'database'       => 'tpshop',
            // 用户名
            'username'       => 'root',
            // 密码
            'password'       => 'shangmei#binfen#0314g'
        ];
        break;

}
return array_merge($base_on_env_config,$base_config);

