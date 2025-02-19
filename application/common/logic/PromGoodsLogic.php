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

namespace app\common\logic;

use app\common\model\PromGoods;
use app\common\model\Goods;
use app\common\model\SpecGoodsPrice;
use app\common\util\TpshopException;
use think\Model;
use think\db;

/**
 * 促销商品逻辑定义
 * Class CatsLogic
 * @package admin\Logic
 */
class PromGoodsLogic extends Prom
{
    protected $promGoods;//促销活动模型
    protected $goods;//商品模型
    protected $specGoodsPrice;//商品规格模型

    public function __construct($goods, $specGoodsPrice)
    {
        parent::__construct();
        $this->goods = $goods;
        $this->specGoodsPrice = $specGoodsPrice;
        $this->promGoods = PromGoods::get($goods['prom_id']);
    }

    public function getPromModel(){
        return $this->promGoods;
    }

    /**
     * 计算促销价格。
     * @param $Price|原价或者规格价格
     * @return float
     */
    public function getPromotionPrice($Price){
        switch ($this->promGoods['type']) {
            case 0:
                $promotionPrice = round($Price * $this->promGoods['expression'] / 100,2);//打折优惠
                break;
            case 1:
                $promotionPrice = $Price - $this->promGoods['expression'];//减价优惠
                break;
            case 2:
                $promotionPrice = $this->promGoods['expression'];//固定金额优惠
                break;
            default:
                $promotionPrice = $Price;//原价
                break;
        }
        $promotionPrice = ($promotionPrice >0 ? $promotionPrice : 0); //防止出现负数
        return $promotionPrice;
    }

    /**
     * 活动是否正在进行
     * @return bool
     */
    public function checkActivityIsAble(){
        if(empty($this->promGoods)){
            return false;
        }
        if(time() > $this->promGoods['start_time'] && time() < $this->promGoods['end_time'] && $this->promGoods['is_end'] == 0){
            return true;
        }
        return false;
    }
    /**
     * 活动是否结束
     * @return bool
     */
    public function checkActivityIsEnd(){
        if(empty($this->promGoods)){
            return true;
        }
        if(time() > $this->promGoods['end_time']){
            return true;
        }
        return false;
    }
    /**
     * 获取商品原始数据
     * @return Goods
     */
    public function getGoodsInfo()
    {
        return $this->goods;
    }
    /**
     * 获取商品转换活动商品的数据
     * @return static
     */
    public function getActivityGoodsInfo(){
        $activityGoods = $this->goods;
        $activityGoods['activity_title'] = $this->promGoods['title'];
        $activityGoods['market_price'] = $this->goods['market_price'];
        $activityGoods['start_time'] = $this->promGoods['start_time'];
        $activityGoods['end_time'] =  $this->promGoods['end_time'];
        //有规格
        if($this->specGoodsPrice){
            $activityGoods['shop_price'] = $this->getPromotionPrice($this->specGoodsPrice['price']);
            $activityGoods['store_count'] = $this->specGoodsPrice['store_count'];
            //如果价格有变化就将市场价等于商品规格价。
            if($activityGoods['shop_price'] != $this->specGoodsPrice['price']){
                $activityGoods['market_price'] = $this->specGoodsPrice['price'];
            }
            $activityGoods['store_count'] = $this->specGoodsPrice['store_count'];
        }else{
            $activityGoods['shop_price'] = $this->getPromotionPrice($this->goods['shop_price']);
            //如果价格有变化就将市场价等于商品规格价。
            if($activityGoods['shop_price'] != $this->goods['shop_price']){
                $activityGoods['market_price'] = $this->specGoodsPrice['price'];
            }
        }
        $activityGoods['prom_detail'] =  $this->promGoods['prom_detail'];
        return $activityGoods;
    }

    /**
     * 该活动是否已经失效
     */
    public function IsAble(){
        if(empty($this->promGoods)){
            return false;
        }
        if(time() > $this->promGoods['end_time']){
            return false;
        }
        if($this->promGoods['is_end'] == 1){
            return false;
        }
        return true;
    }
/*    /**
     * @param $buyGoods
     * @return array
     *
    public function buyNow($buyGoods){
        if(!$this->checkActivityIsEnd() && $this->checkActivityIsAble()){
            $buyGoods['member_goods_price'] = $this->getPromotionPrice($buyGoods['member_goods_price']);
        }
        $buyGoods['prom_type'] = 1;
        $buyGoods['prom_id'] = $this->promGoods['id'];
        return array('status' => 1, 'msg' => 'success', 'result' => ['buy_goods'=>$buyGoods]);
    }*/

    /**
     * 促销商品立即购买
     * @param $buyGoods
     * @return mixed
     * @throws TpshopException
     */
    public function buyNow($buyGoods){
        if(!$this->checkActivityIsEnd() && $this->checkActivityIsAble()){
            if($buyGoods['goods_num'] > $this->promGoods['buy_limit']){
                throw new TpshopException('促销商品立即购买', 0, ['status' => 0, 'msg' => '每人限购' . $this->promGoods['buy_limit'] . '件', 'result' => '']);
            }
            $buyGoods['member_goods_price'] = $this->getPromotionPrice($buyGoods['member_goods_price']);
        }
        $residue_buy_limit = $this->getPromoGoodsResidueGoodsNum($buyGoods['user_id']); //获取用户还能购买商品数量
        $userPromOrderGoodsNum = $this->getUserPromOrderGoodsNum($buyGoods['user_id']); //获取用户已购商品数量
        $userBuyGoodsNum = $buyGoods['goods_num'] + $userPromOrderGoodsNum;  //已经下单+要买
        if($userBuyGoodsNum > $this->promGoods['buy_limit']){
            throw new TpshopException('促销商品立即购买', 0, ['status' => 0, 'msg' => '每人限购'.$this->promGoods['buy_limit'].'件，您已下单'.$userPromOrderGoodsNum.'件', 'result' => '']);
        }
        if($buyGoods['goods_num'] > $residue_buy_limit){  //不算购物车的
            throw new TpshopException('促销商品立即购买', 0, ['status' => 0, 'msg' => '商品库存不足，你只能购买'.$residue_buy_limit, 'result' => '']);
        }
        $buyGoods['prom_type'] = 3;
        $buyGoods['prom_id'] = $this->promGoods['id'];
        return $buyGoods;
    }

    /**
     * 获取用户抢购已购商品数量
     * @param $user_id
     * @return float|int
     */
    public function getUserPromOrderGoodsNum($user_id){
        $orderWhere = [
            'user_id'=>$user_id,
            'order_status' => ['<>', 3],
            'add_time' => ['between', [$this->promGoods['start_time'], $this->promGoods['end_time']]]
        ];
        $order_id_arr = Db::name('order')->where($orderWhere)->getField('order_id', true);
        if ($order_id_arr) {
            $orderGoodsWhere = [
                'prom_id' => $this->promGoods['id'],
                'prom_type' => 3,
                'order_id' => ['in', implode(',', $order_id_arr)],
                'goods_id' => $this->goods['goods_id'],
            ];
            if($this->specGoodsPrice){
                $orderGoodsWhere[ 'spec_key'] = $this->specGoodsPrice['key'];
            }
            $goods_num = DB::name('order_goods')->where($orderGoodsWhere)->sum('goods_num');
            if($goods_num){
                return $goods_num;
            }else{
                return 0;
            }
        } else {
            return 0;
        }
    }
    /**
     * 获取用户剩余购买促销商品数量
     * @param $user_id
     * @return mixed
     */
    public function getPromoGoodsResidueGoodsNum($user_id){
        $user_purchase_num = $this->getUserPromOrderGoodsNum($user_id); //用户已购商品数量
        $promGoodsItem = Db::name('prom_goods_list')->where(['goods_id'=>$this->goods['goods_id'],'promote_id'=>$this->promGoods['id']])->find();
        $limt_num = $promGoodsItem['goods_num'] - $promGoodsItem['buy_num'];
        //限购》已购
        if ($this->promGoods['buy_limit']>0) {
            $residue_buy_limit = $this->promGoods['buy_limit'] - $user_purchase_num;//用户还能买的数量
        } else {
            $residue_buy_limit = $this->goods['store_count'];//无购买数量限制
        }
        $store_count = $this->goods['store_count'];  //剩余库存
        if($this->specGoodsPrice){
            $store_count = $this->specGoodsPrice['store_count'];
        }
        return min($residue_buy_limit,$store_count, $limt_num);
    }
}