<?php
/**
 * tpshop
 * ============================================================================
 * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和使用 .
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 如果商业用途务必到官方购买正版授权, 以免引起不必要的法律纠纷.
 * ============================================================================
 */

namespace app\common\logic;
use app\common\model\WxMaterial;
use think\Config;


/**
 * 编辑器类逻辑
 * Class EditorLogic
 * @package app\common\logic
 */
class EditorLogic
{
    /**
     * 水印
     * @param $img_path
     */
    public function waterImage($img_path)
    {
        $image = \think\Image::open($img_path);
        $water = tpCache('water');  //水印配置
        $return_data['mark_type'] = $water['mark_type'];
        if ($water['is_mark'] == 1 && $image->width() > $water['mark_width'] && $image->height() > $water['mark_height']) {
            if ($water['mark_type'] == 'text') {
                $ttf = './hgzb.ttf';
                if (file_exists($ttf)) {
                    $size = $water['mark_txt_size'] ? $water['mark_txt_size'] : 30;
                    $color = $water['mark_txt_color'] ?: '#000000';
                    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $color = '#000000';
                    }
                    $transparency = intval((100 - $water['mark_degree']) * (127 / 100));
                    $color .= dechex($transparency);
                    $image->open($img_path)->text($water['mark_txt'], $ttf, $size, $color, $water['sel'])->save($img_path);
                    $return_data['mark_txt'] = $water['mark_txt'];
                }
            } else {
                $waterPath = "." . $water['mark_img'];
                $quality = $water['mark_quality'] ? $water['mark_quality'] : 80;
                $waterTempPath = dirname($waterPath) . '/temp_' . basename($waterPath);
                $image->open($waterPath)->save($waterTempPath, null, $quality);
                $image->open($img_path)->water($waterTempPath, $water['sel'], $water['mark_degree'])->save($img_path);
                @unlink($waterTempPath);
            }
        }
    }

    /**
     * 保存上传的图片
     * @param $file \think\File
     * @param $save_path
     */
    public function saveUploadImage($file, $save_path)
    {
        $return_url = '';
        $state = "SUCCESS";
        $new_path = $save_path.date('Y').'/'.date('m-d').'/';

        $waterPaths = Config::get('aliyun_oss.save_path'); //哪种路径的图片需要放oss
        if (in_array($save_path, $waterPaths) && tpCache('oss.oss_switch')) {
            //商品图片可选择存放在oss
            $object = UPLOAD_PATH.$new_path.md5(time().mt_rand(10000,99999)).'.'.pathinfo($file->getInfo('name'), PATHINFO_EXTENSION);
            $ossClient = new \app\common\logic\OssLogic;
            $return_url = $ossClient->uploadFile($file->getRealPath(), $object);
            $real_path = $file->getRealPath();
            $file = null;//关闭文件句柄，不然无法删除
            @unlink($real_path); //上传后删除
            if (!$return_url) {
                $state = "ERROR" . $ossClient->getError();
                $return_url = '';
            }
        } else {
            // 移动到框架应用根目录/public/uploads/ 目录下
            $info = $file->rule(function ($file) {
                return  md5(mt_rand()); // 使用自定义的文件保存规则
            })->move(UPLOAD_PATH.$new_path);
            if (!$info) {
                $state = "ERROR" . $file->getError();
            } else {
                $return_url = '/'.UPLOAD_PATH.$new_path.$info->getSaveName();
                if ($save_path =='goods/') {  //只有商品图才打水印
                    $this->waterImage(".".$return_url);  //水印
                }

                $state = $this->uploadWechatImage($save_path, $return_url);
                if ($state != 'SUCCESS') {
                    $info = null;//关闭文件句柄，不然无法删除
                    @unlink('.' . $return_url);
                    $return_url = '';
                }
            }
        }

        return [
            'state' => $state,
            'url'   => $return_url
        ];
    }

    /**
     * 上传微信公众号图片
     * @param $save_path
     * @param $return_url
     * @return string
     */
    private function uploadWechatImage($save_path, $return_url)
    {
        $state = "SUCCESS";

        //微信公众号图片,weixin_mp_image存放永久图片，weixin_mp_news存放文章图片，两者不能共用
        if ($save_path == 'weixin_mp_image/') {
            $wechat = new \app\common\logic\wechat\WechatUtil;
            $data = $wechat->uploadMaterial('.' . $return_url, 'image');
            if ($data === false) {
                $state = $wechat->getError();
            } else {
                WxMaterial::create([
                    'type' => WxMaterial::TYPE_IMAGE,
                    'key'  => md5($return_url),
                    'media_id' => $data['media_id'],
                    'update_time' => time(),
                    'data' => [
                        'url' => $return_url,
                        'mp_url' => $data['url'],
                    ]
                ]);
            }
        } elseif ($save_path == 'weixin_mp_news/') {
            $wechat = new \app\common\logic\wechat\WechatUtil;
            $news_img_url = $wechat->uploadNewsImage('.' . $return_url);
            if ($news_img_url === false) {
                $state = $wechat->getError();
            } else {
                WxMaterial::create([
                    'type' => WxMaterial::TYPE_NEWS_IMAGE,
                    'key' => md5($return_url),
                    'update_time' => time(),
                    'data' => [
                        'url' => $return_url,
                        'mp_url' => $news_img_url,
                    ]
                ]);
            }
        }

        return $state;
    }
}