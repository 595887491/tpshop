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
 * Author: dyr
 * Date: 2016-08-23
 */

namespace app\common\model;

use app\common\logic\OrderLogic;
use app\common\logic\UsersLogic;
use think\Model;
use think\Db;

/**
 * @package Home\Model
 */
class Order extends Model
{
    protected $resultSetType = 'collection';

    //获取所有订单商品
    public function OrderGoods()
    {
        return $this->hasMany('OrderGoods','order_id','order_id')->field('*,(goods_num * member_goods_price) AS goods_total');

    }
    
    /**
     * 获取订单状态对应的中文
     * @param $value
     * @param $data
     * @return mixed
     */
    public function getOrderStatusDetailAttr($value, $data)
    {
        $data_status_arr = C('ORDER_STATUS_DESC');
        // 货到付款
        if ($data['pay_code'] == 'cod') {
            if (in_array($data['order_status'], array(0, 1)) && $data['shipping_status'] == 0) {
                return $data_status_arr['WAITSEND']; //'待发货',
            }
        } else {
            // 非货到付款
            if ($data['pay_status'] == 0 && $data['order_status'] == 0){
                return $data_status_arr['WAITPAY']; //'待支付',
            }
            if ($data['pay_status'] == 1 && in_array($data['order_status'], array(0, 1)) && $data['shipping_status'] != 1){
                if($data['prom_type'] == 6){
                    if($data['order_status'] == 0){
                        return '待处理';
                    }else{
                        return $data_status_arr['WAITSEND']; //'待发货',
                    }
                }
                else {
                    return $data_status_arr['WAITSEND']; //'待发货',
                }
            }
        }
        if (($data['shipping_status'] == 1) && ($data['order_status'] == 1)){
            return $data_status_arr['WAITRECEIVE']; //'待收货',
        }
        if ($data['order_status'] == 2 && $data['is_comment'] == 1) {
            return $data_status_arr['FINISH']; //'待评价',
        }
        if ($data['order_status'] == 2) {
            return $data_status_arr['WAITCCOMMENT']; //'待评价',
        }
        if ($data['order_status'] == 3){
            return $data_status_arr['CANCEL']; //'已取消',
        }
        if ($data['order_status'] == 4){
            return $data_status_arr['FINISH']; //'已完成',
        }
        return $data_status_arr['OTHER'];
    }

    /**
     *  只有在订单为拼团才有用:prom_type = 6
     */
    public function teamActivity()
    {
        return $this->hasOne('TeamActivity', 'team_id', 'prom_id');
    }

    public function teamFollow(){
        return $this->hasOne('TeamFollow','order_id','order_id');
    }


    /**
     * 订单详细收货地址
     * @param $value
     * @param $data
     * @return string
     */
    public function getFullAddressAttr($value, $data)
    {
        $province =  Region::where(['id'=>$data['province']])->value('name');
        $city = Region::where(['id'=>$data['city']])->value('name');
        $district =Region::where(['id'=>$data['district']])->value('name');
        $adderss = $province.'，'.$city.'，'.$district.'，'.$data['address'];
        return $adderss;
    }

    public function teamFound(){
        return $this->hasOne('TeamFound','order_id','order_id');
    }

    /**
     * 获取当前可操作的按钮(后台)
     * @param $data
     * @return array
     */
    public function getAdminOrderButtonAttr($value, $data){
        /*
         *  操作按钮汇总 ：付款、设为未付款、确认、取消确认、无效、去发货、确认收货、申请退货
         *
         */
        $os = $data['order_status'];//订单状态
        $ss = $data['shipping_status'];//发货状态
        $ps = $data['pay_status'];//支付状态
        $pt = $data['prom_type'];//订单类型：0默认1抢购2团购3优惠4预售5虚拟6拼团
        $btn = array();
        if($data['pay_code'] == 'cod') {
            if($os == 0 && $ss == 0){
                if($pt != 6){
                    $btn['confirm'] = '确认';
                }
            }elseif($os == 1 && ($ss == 0 || $ss == 2)){
                $btn['delivery'] = '去发货';
                if($pt != 6){
                    $btn['cancel'] = '取消确认';
                }
            }elseif($ss == 1 && $os == 1 && $ps == 0){
                $btn['pay'] = '付款';
            }elseif($ps == 1 && $ss == 1 && $os == 1){
                if($pt != 6){
                    $btn['pay_cancel'] = '设为未付款';
                }
            }
        }else{
            if($ps == 0 && $os == 0 || $ps == 2){
                $btn['pay'] = '付款';
            }elseif($os == 0 && $ps == 1){
                if($pt != 6){
                    $btn['pay_cancel'] = '设为未付款';
                    $btn['confirm'] = '确认';
                }
            }elseif($os == 1 && $ps == 1 && ($ss == 0 || $ss == 2)){
                if($pt != 6){
                    $btn['cancel'] = '取消确认';
                }
                $btn['delivery'] = '去发货';
            }
        }

        if($ss == 1 && $os == 1 && $ps == 1){
//        	$btn['delivery_confirm'] = '确认收货';
            $btn['refund'] = '申请退货';
        }elseif($os == 2 || $os == 4){
            $btn['refund'] = '申请退货';
        }elseif($os == 3 || $os == 5){
            $btn['remove'] = '移除';
        }
        if($os != 5){
            $btn['invalid'] = '无效';
        }
        return $btn;
    }

    /**
     * 获取订单状态的显示按钮（用户）
     * @param $value
     * @param $data
     * @return mixed
     */
    public function getOrderButtonAttr($value, $data)
    {
        /**
         *  订单用户端显示按钮
         * 去支付     AND pay_status=0 AND order_status=0 AND pay_code ! ="cod"
         * 取消按钮  AND pay_status=0 AND shipping_status=0 AND order_status=0
         * 确认收货  AND shipping_status=1 AND order_status=0
         * 评价      AND order_status=1
         * 查看物流  if(!empty(物流单号))
         */
        $btn_arr = array(
            'pay_btn' => 0, // 去支付按钮
            'cancel_btn' => 0, // 取消按钮
            'receive_btn' => 0, // 确认收货
            'comment_btn' => 0, // 评价按钮
            'shipping_btn' => 0, // 查看物流
            'return_btn' => 0, // 退货按钮 (联系客服)
        );
        // 三个月(90天)内的订单才可以进行有操作按钮. 三个月(90天)以外的过了退货 换货期, 即便是保修也让他联系厂家, 不走线上
        if (time() - $data['add_time'] > (86400 * 90)) {
            return $btn_arr;
        }
        // 货到付款
        if ($data['pay_code'] == 'cod') {
            if (($data['order_status'] == 0 || $data['order_status'] == 1) && $data['shipping_status'] == 0)
            {
                $btn_arr['cancel_btn'] = 1; // 取消按钮 (联系客服)
            }
            if ($data['shipping_status'] == 1 && $data['order_status'] == 1) //待收货
            {
                $btn_arr['receive_btn'] = 1;  // 确认收货
                $btn_arr['return_btn'] = 1; // 退货按钮 (联系客服)
            }
        } // 非货到付款
        else {
            if ($data['pay_status'] == 0 && $data['order_status'] == 0) // 待支付
            {
                $btn_arr['pay_btn'] = 1; // 去支付按钮
                $btn_arr['cancel_btn'] = 1; // 取消按钮
            }
            if ($data['pay_status'] == 1 && $data['order_status'] < 2 && $data['shipping_status'] == 0) {
                if ($data['prom_type'] == 6) {
                    $btn_arr['cancel_btn'] = 0; // 取消按钮
                } else {
                    $btn_arr['cancel_btn'] = 1; // 取消按钮
                }
            }
            if ($data['pay_status'] == 1 && $data['order_status'] == 1 && $data['shipping_status'] == 1) {
                $btn_arr['receive_btn'] = 1;  // 确认收货
            }
        }

        if ($data['order_status'] == 4) {
            $btn_arr['return_btn'] = 1; // 退货按钮 (联系客服)
        }
        if ($data['order_status'] == 2) {
            if ($data['is_comment'] == 0) $btn_arr['comment_btn'] = 1;  // 评价按钮
            $btn_arr['return_btn'] = 1; // 退货按钮 (联系客服)
        }
        if ($data['shipping_status'] > 0 && $data['order_status'] == 1) {
            $btn_arr['shipping_btn'] = 1; // 查看物流
        }
        if ($data['shipping_status'] == 2 && $data['order_status'] == 1) {
            $btn_arr['return_btn'] = 1; // 退货按钮 (联系客服)
        }
        if($data['order_status'] == 3 && ($data['pay_status'] == 1 || $data['pay_status'] == 4)){
        	$btn_arr['cancel_info'] = 1; // 取消订单详情
        }
        return $btn_arr;
    }

    /**
     * 虚拟订单操作按钮
     * @param $value
     * @param $data
     * @return array
     */
    public function getVirtualOrderButtonAttr($value, $data){
        $vr_order_code = Db::name('vr_order_code')->where(['order_id'=>$data['order_id']])->find();
        $Virtual_btn_arr = array(
            'pay_btn' => 0, // 去支付按钮
            'cancel_btn' => 0, // 取消按钮
            'receive_btn' => 0, // 确认收货
            'comment_btn' => 0, // 评价按钮
        );
        if ($data['pay_status'] == 0 && $data['order_status'] == 0) {   // 待支付
            $Virtual_btn_arr['pay_btn'] = 1; // 去支付按钮
            $Virtual_btn_arr['cancel_btn'] = 1; //未支付取消按钮
        }
        if(!empty($vr_order_code)){
            if ($data['pay_status']==1 && $data['order_status']<2 && $vr_order_code['vr_state']!=1 && $vr_order_code['refund_lock']<1)
            {
                if ($vr_order_code['vr_indate'] > time() ) {
                    $Virtual_btn_arr['cancel_btn'] = 2; // 已支付取消按钮
                }
                if ($vr_order_code['vr_indate'] < time() && $vr_order_code['vr_invalid_refund'] == 1)
                {
                    $Virtual_btn_arr['cancel_btn'] = 2; // 已支付取消按钮
                    M('vr_order_code')->where(array('order_id'=>$data['order_id']))->update(['vr_state'=>2]);
                }
                $Virtual_btn_arr['receive_btn'] = 1;
            }
            if ($data['order_status'] == 2) {
                if ($data['is_comment'] == 0) $Virtual_btn_arr['comment_btn'] = 1;  // 评价按钮
            }
        }
        return $Virtual_btn_arr;
    }

    public function getAddressRegionAttr($value, $data){
        $regions = Db::name('region')->where('id', 'IN', [$data['province'], $data['city'], $data['district'],$data['twon']])->order('level desc')->select();
        $address = '';
        if($regions){
            foreach($regions as $regionKey=>$regionVal){
                $address = $regionVal['name'] . $address;
            }
        }
        return $address;
    }

    public function getPayStatusDetailAttr($value, $data)
    {
        $pay_status = config('PAY_STATUS');
        return $pay_status[$data['pay_status']];
    }

    public function getShippingStatusDetailAttr($value, $data)
    {
        $shipping_status = config('SHIPPING_STATUS');
        return $shipping_status[$data['shipping_status']];
    }


    /**
     * @Author: 陈静
     * @Date: 2018/04/03 22:38:06
     * @Description: 通过订单号获取订单信息
     */
    public function getOrderInfoByTradeNo($trade_no)
    {
        return $this->where('order_sn','=',$trade_no)
            ->field('order_id,order_sn,user_id,pay_status,order_amount,user_money,shipping_price,order_status,user_note,add_time')
            ->find();
    }


    /*
     * @Author: 赵磊
     * @Description: 通过订单id获取订单信息
     * */
    public function orderDetail($order_id)
    {
        return $this->where('order_id','=',$order_id)
//            ->field('order_id,order_sn,user_id,pay_status,order_amount,user_money,shipping_price,order_status,add_time')
            ->find();
    }

    /*
     * @Author: 赵磊
     * @Description: 通过user_id获取美年大健康订单信息
     *  */
    public function VirtualOrderInfo($userId)
    {
        $vrOrderCode = new VrOrderCode();
        $fields = 'a.order_id,a.order_sn,a.order_status,a.pay_status,a.goods_price,a.add_time,a.total_amount
                    ,b.goods_name,b.goods_num,b.goods_id,b.rec_id,b.is_comment
                    ,c.original_img';
        $condition = "a.user_id = $userId and a.prom_type = 5";
        $orderInfo = $this
            ->alias('a')
            ->field($fields)
            ->join('tp_order_goods b','a.order_id = b.order_id')
            ->join('tp_goods c','b.goods_id = c.goods_id')
            ->where($condition)
            ->select();
        if (!empty($orderInfo)){
            $orderInfo = $orderInfo->toArray();
            $res = array_column($orderInfo,'add_time');//获取二维数组中某个值
            array_multisort($res,SORT_DESC,$orderInfo);//根据下单时间倒序排序
        }
        for ($i=0;$i<count($orderInfo);$i++){
            $is_consume = $vrOrderCode->listConsumeStaus($orderInfo[$i]['order_id']);
            $orderInfo[$i]['is_consume'] = $is_consume; //列表页消费状态

        }
        return $orderInfo;
    }


    /*
    * @Author: 赵磊
    * @Description: 通过order_id获取美年大健康订单详情信息
    *  */
    public function VirtualOrderMainInfo($orderId)
    {
        $fields = 'a.order_sn,a.user_money,a.goods_price,a.pay_time,a.order_amount,a.order_status,a.pay_status,a.order_id,a.total_amount
                    ,b.goods_num,b.goods_name,b.goods_id,b.is_comment,b.rec_id
                    ,c.original_img,c.virtual_desc';
        $orderInfo = $this
            ->alias('a')
            ->field($fields)
            ->join('tp_order_goods b','a.order_id = b.order_id')
            ->join('tp_goods c','b.goods_id = c.goods_id')
            ->where('a.order_id',$orderId)
            ->find();
        if ($orderInfo) $orderInfo = $orderInfo->toArray();
        $Vr = new VrOrderCode();
        $orderInfo['is_consume'] = $Vr->listConsumeStaus($orderId); //卡券消费状态
        $vrInfo = $Vr->getVrInfo($orderInfo['order_id']);//根据订单id获取虚拟订单信息
        $orderInfo['pay_time'] = date('Y-m-d',$orderInfo['pay_time']);//付款时间
        $orderInfo['endTime'] = date('Y-m-d',$vrInfo[0]['vr_indate']);//缤纷券有效期
        $orderInfo['vrInfo'] = $vrInfo;//虚拟订单兑换码二维数组
        return $orderInfo;
    }



    /*
     * @Author :赵磊
     * 用户确认收货订单或完成订单的数量
     */
    public function finishOrderNum($user)
    {
        $where['user_id'] = $user;
        $where['order_status'] = ['in',[2,4]];//订单状态为已收货或已完成
        $count = $this
            ->where($where)
            ->count();
        return $count;
    }


    /*
     * @Author:赵磊
     * 投票活动中奖商品发放自动下单
     * */
    public function addVoteOrder($goods_id,$user_id)
    {
        $condition['user_id'] = $user_id;
        $condition['admin_note'] = '汉服投票中奖(禁止修改)';
        $vote_order = (new Order())->where($condition)->find();
        if ($vote_order) return $vote_order['order_id'];//已获奖,多次添加地址,返回原有订单号

        //查询商品价格
        $goodsModel = new \app\common\model\Goods();
        $goodsInfo = $goodsModel->where('goods_id',$goods_id)->find();
        $goodsInfo->market_price = round($goodsInfo->market_price,2);
        //生成订单
        $address = Db::name('UserAddress')->where("user_id", $user_id)->order('address_id desc')->find();
        $data['order_sn'] = (new OrderLogic())->get_order_sn('partner');
        $data['user_id'] = $user_id;
        $data['order_status'] = 0;
        $data['pay_status'] = 1;
        $data['pay_time'] = time();

        $data['consignee'] = $address['consignee'] ? $address['consignee'] : '';
        $data['province'] = $address['province'] ? $address['province'] : '';
        $data['city'] = $address['city'] ? $address['city'] : '';
        $data['district'] = $address['district'] ? $address['district'] : '';
        $data['address'] = $address['address'] ? $address['address'] : '';
        $data['mobile'] = $address['mobile'] ? $address['mobile'] : '';
        $data['admin_note'] = '汉服投票中奖(禁止修改)';
        $data['goods_price'] = $goodsInfo->shop_price;
        $data['order_amount'] = 0;//中奖应付款
        $data['total_amount'] = $goodsInfo->shop_price;
        $data['add_time'] = time();

        $res = Db::name('order')->insertGetId($data);
        Db::startTrans();
        if ($res) {
            //查询商品信息
            $data1['order_id'] = $res;
            $data1['goods_id'] = $goodsInfo->goods_id;
            $data1['goods_name'] = $goodsInfo->goods_name;
            $data1['goods_sn'] = $goodsInfo->goods_sn;
            $data1['goods_num'] = 1;
            $data1['final_price'] = $goodsInfo->shop_price;
            $data1['goods_price'] = $goodsInfo->shop_price;
            $data1['member_goods_price'] = $goodsInfo->shop_price;

            $res1 = (new OrderGoods())->insert($data1);

            if ($res1) {
                Db::commit();
                return $res;
            }
            Db::rollback();
        }
        Db::rollback();

        }



}