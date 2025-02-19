<?php
/**
 * tpshop
 * ============================================================================
 * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和使用 .
 * 不允许对程序代码以任何形式任何目的的再发布。
 * ============================================================================
 * Author: IT宇宙人
 * Date: 2015-09-09
 */
namespace app\admin\model;
use think\Model;
class FlashSale extends Model {
    //自定义初始化
    protected static function init()
    {
        //TODO:自定义的初始化
    }

    public function specGoodsPrice()
    {
        return $this->hasOne('SpecGoodsPrice','item_id','item_id');
    }

    public function goods()
    {
        return $this->hasOne('goods','prom_id','id');
    }

    /**
     * @param bool $count 统计数量
     */
    public function flashList($count=false,$first=0,$length=20){
        $subQuery = $this->table('tp_flash_sale')->field('title,count(1) as goods_count, start_time,end_time,sum(order_num) as order_sum, is_end')
            ->group('start_time')
            ->order('start_time','desc')
            ->buildSql();
        if ($count) {
            $count = $this->table($subQuery.' b')->count();
            return $count;
        }
        $list = $this->table($subQuery.' b')
            ->field('b.*')
            ->limit($first,$length)
            ->select();
        return $list;
    }
    //状态描述
    public function getStatusDesc($data){
        if($data['is_end'] == 1){
            return '已结束';
        } else {
            if($data['start_time'] > time()){
                return '未开始';
            }else if ($data['start_time'] < time() && $data['end_time'] > time()) {
                return '进行中';
            }else{
                return '已过期';
            }
        }
    }
}
