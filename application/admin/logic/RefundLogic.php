<?php
/**
 * tpshop
 * ============================================================================
 * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 商业用途务必到官方购买正版授权, 使用盗版将严厉追究您的法律责任。
 * 采用最新Thinkphp5助手函数特性实现单字母函数M D U等简写方式
 * ============================================================================
 * Author: IT宇宙人
 * Date: 2015-09-09
 */

namespace app\admin\logic;
use think\Model;
use think\Db;
/**
 * 分类逻辑定义
 * Class CatsLogic
 * @package Home\Logic
 */
class RefundLogic extends Model
{

    //订单商品售后退款
    function updateRefundGoods($rec_id,$refund_type=0){
        $order_goods = M('order_goods')->where(array('rec_id'=>$rec_id))->find();
        $return_goods = M('return_goods')->where(array('rec_id'=>$rec_id))->find();
        $updata = array('refund_type'=>$refund_type,'refund_time'=>time(),'status'=>5);
        //使用积分或者余额抵扣部分原路退还
        if(($return_goods['refund_deposit']>0 || $return_goods['refund_integral']>0)){
            accountLog($return_goods['user_id'],$return_goods['refund_deposit'],$return_goods['refund_integral'],'用户申请商品退款',0,$return_goods['order_id'],$return_goods['order_sn']);
        }
        //在线支付金额退到余额去
        if($refund_type==1 && $return_goods['refund_money']>0){
            accountLog($return_goods['user_id'],$return_goods['refund_money'],0,'用户申请商品退款',0,$return_goods['order_id'],$return_goods['order_sn']);
        }
        M('return_goods')->where(array('rec_id'=>$rec_id))->save($updata);//更新退款申请状态
        M('order_goods')->where(array('rec_id'=>$rec_id))->save(array('is_send'=>3));//修改订单商品状态
        if($return_goods['is_receive'] == 1){
            //赠送积分追回
            if($order_goods['give_integral']>0){
                $user = get_user_info($return_goods['user_id']);
                if($order_goods['give_integral']<$user['pay_points']){
                    accountLog($return_goods['user_id'],0,-$order_goods['give_integral'],'退货积分追回',0,$return_goods['order_id'],$return_goods['order_sn']);
                }
            }
            //追回订单商品赠送的优惠券
            $coupon_info = M('coupon_list')->where(array('uid'=>$return_goods['user_id'],'get_order_id'=>$return_goods['order_id']))->find();
            if(!empty($coupon_info)){
                if($coupon_info['status'] == 1) { //如果优惠券被使用,那么从退款里扣
                    $coupon = M('coupon')->where(array('id' => $coupon_info['cid']))->find();
                    if ($return_goods['refund_money'] > $coupon['money']) {
                        //退款金额大于优惠券金额直接扣
                        $return_goods['refund_money'] = $return_goods['refund_money'] - $coupon['money'];//更新实际退款金额
                        M('return_goods')->where(['id' => $return_goods['id']])->save(['refund_money' => $return_goods['refund_money']]);
                    }else{
                        //否则从退还余额里扣
                        $return_goods['refund_deposit'] = $return_goods['refund_deposit'] - $coupon['money'];//更新实际退还余额
                        M('return_goods')->where(['id' => $return_goods['id']])->save(['refund_deposit' => $return_goods['refund_deposit']]);
                    }
                }else {
                    M('coupon_list')->where(array('id' => $coupon_info['id']))->delete();
                    M('coupon')->where(array('id' => $coupon_info['cid']))->setDec('send_num');//优惠券追回
                }
            }
        }
        //退还使用的优惠券
        $order_goods_count =  M('order_goods')->where(array('order_id'=>$return_goods['order_id']))->sum('goods_num');
        $return_goods_count = M('return_goods')->where(array('order_id'=>$return_goods['order_id']))->sum('goods_num');
        if($order_goods_count == $return_goods_count){
            $coupon_list = M('coupon_list')->where(['uid'=>$return_goods['user_id'],'order_id'=>$return_goods['order_id']])->find();
            if(!empty($coupon_list)){
                $update_coupon_data = ['status'=>0,'use_time'=>0,'order_id'=>0];
                M('coupon_list')->where(['id'=>$coupon_list['id'],'status'=>1])->save($update_coupon_data);//符合条件的，优惠券就退给他
            }
        }
        $expense_data = array('money'=>$return_goods['refund_money']+$return_goods['refund_deposit'],'log_type_id'=>$rec_id,'type'=>3,'user_id'=>$return_goods['user_id']);
        expenseLog($expense_data);//退款记录日志
    }
    
    
    /**
     * 取消订单退还余额，优惠券等
     * @param $order
     * @return bool
     */
    function updateRefundOrder($order,$type=0){
        //使用积分或者余额抵扣部分一一退还
        if ($order['user_money'] > 0 || $order['integral'] > 0) {
            $update_money_res = accountLog($order['user_id'], $order['user_money'], $order['integral'], '用户申请订单退款', 0, $order['order_id'], $order['order_sn']);
            if(!$update_money_res){
                return false;
            }
        }
    
        //在线支付金额退到余额
        if($order['order_amount']>0 && $type == 1){
            //改方法已经是退回余额, 不需要判断原路退回还是退还到余额
            accountLog($order['user_id'],$order['order_amount'],0,'用户取消订单退款',0,$order['order_id'],$order['order_sn']);
        }
        //符合条件的，该笔订单使用的优惠券就退还
        $coupon_list = M('coupon_list')->where(['uid'=>$order['user_id'],'order_id'=>$order['order_id']])->find();
        if(!empty($coupon_list)){
            $update_coupon_data = ['status'=>0,'use_time'=>0,'order_id'=>0];
            M('coupon_list')->where(['id'=>$coupon_list['id'],'status'=>1])->save($update_coupon_data);
        }
        M('order')->where(array('order_id'=>$order['order_id']))->save(array('pay_status'=>3)); //更改订单状态
//        $orderLogic = new app\common\logic\OrderLogic();
        $orderLogic = new \app\common\logic\OrderLogic();
        $orderLogic->alterReturnGoodsInventory($order);//取消订单后改变库存
        $expense_data = [
            'money'         => $order['user_money'],
            'log_type_id'   => $order['order_id'],
            'type'          => 2,
            'user_id'       => $order['user_id'],
        ];
        //减掉用户的累计消费金额
        Db::name('users')->where('user_id',$order['user_id'])->update(['total_amount'=>['exp','total_amount - '.$order['order_amount']]]);

        expenseLog($expense_data);//平台支出记录
        return true;
    }


    /**
     * @Author : 赵磊
     * 单个卡券订单退还余额
     * @param $order
     * @return bool
     */
    function updateVrCodeRefundOrder($order,$type=0){
        //使用余额抵扣部分一一退还
        if ($order['user_money'] > 0 && $order['remainingRefund']<$order['goods_price'] && $type == 0) {
            $update_money_res = accountLog($order['user_id'], $order['goods_price']-$order['remainingRefund'], 0, '用户申请订单退款', 0, $order['order_id'], $order['order_sn']);
            if(!$update_money_res){
                return false;
            }
        }

        //在线支付金额退到余额
        if($order['order_amount']>0 && $type == 1){
            //改方法已经是退回余额, 不需要判断原路退回还是退还到余额
            accountLog($order['user_id'],$order['goods_price'],0,'用户取消订单退款',0,$order['order_id'],$order['order_sn']);
        }
        //余额支付金额退到余额
        if($order['user_money']>0 && $type == 2){
            //改方法已经是退回余额, 不需要判断原路退回还是退还到余额
            accountLog($order['user_id'],$order['goods_price'],0,'用户取消订单退款',0,$order['order_id'],$order['order_sn']);
        }

        M('vr_order_code')->where(array('rec_id'=>$order['rec_id']))->save(array('refund_lock'=>3,'remark'=>$order['refundRemark'])); //更改兑换码状态为退款成功

        $orderLogic = new \app\common\logic\OrderLogic();
        $orderLogic->alterReturnGoodsInventory($order);//取消订单后改变库存
        $expense_data = [
            'money'         => $order['user_money'],
            'log_type_id'   => $order['order_id'],
            'type'          => 2,
            'user_id'       => $order['user_id'],
        ];
        //减掉用户的累计消费金额
        if ($order['remainingRefund'] >= $order['goods_price'])$goodsPrice = ($order['remainingRefund'] - $order['goods_price']);
        if ($order['remainingRefund'] < $order['goods_price'])$goodsPrice = ($order['goods_price'] - $order['remainingRefund']);
        Db::name('users')->where('user_id',$order['user_id'])->update(['total_amount'=>['exp','total_amount - '.$goodsPrice]]);//退款订单卡券单价

        expenseLog($expense_data);//平台支出记录
        return true;
    }
    
}