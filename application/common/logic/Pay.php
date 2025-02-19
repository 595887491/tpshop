<?php
/**
 * tpshop
 * ============================================================================
 * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和使用 .
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 采用最新Thinkphp5助手函数特性实现单字母函数M D U等简写方式
 * ============================================================================
 * Author: dyr
 * Date: 2017-12-04
 */

namespace app\common\logic;
use app\common\model\CouponList;
use app\common\util\TpshopException;
use think\Model;
use think\Db;
/**
 * 计算价格类
 * Class CatsLogic
 * @package Home\Logic
 */
class Pay
{
    protected $payList;
    protected $userId;
    protected $user;

    private $totalAmount = 0;//订单总价
    private $orderAmount = 0;//应付金额
    private $shippingPrice = 0;//物流费
    private $goodsPrice = 0;//商品总价
    private $cutFee = 0;//共节约多少钱
    private $totalNum = 0;// 商品总共数量
    private $integralMoney = 0;//积分抵消金额
    private $userMoney = 0;//使用余额
    private $payPoints = 0;//使用积分
    private $couponPrice = 0;//优惠券抵消金额

    private $orderPromId;//订单优惠ID
    private $orderPromAmount = 0;//订单优惠金额
    private $couponId;

    /**
     * 计算订单表的普通订单商品
     * @param $order_goods
     * @throws TpshopException
     */
    public function payOrder($order_goods){
        $this->payList = $order_goods;
        $order = Db::name('order')->where('order_id',  $this->payList[0]['order_id'])->find();
        if(empty($order)){
            throw new TpshopException('计算订单价格', 0, ['status' => -9, 'msg' => '找不到订单数据', 'result' => '']);
        }
        $reduce = tpCache('shopping.reduce');
        if($order['pay_status'] == 0 && $reduce == 2){
            $goodsListCount = count($this->payList);
            for ($payCursor = 0; $payCursor < $goodsListCount; $payCursor++) {
                $goods_stock = getGoodNum($this->payList[$payCursor]['goods_id'], $this->payList[$payCursor]['spec_key']); // 最多可购买的库存数量
                if($goods_stock <= 0 && $this->payList[$payCursor]['goods_num'] > $goods_stock){
                    throw new TpshopException('计算订单价格', 0, ['status' => -9, 'msg' => $this->payList[$payCursor]['goods_name'].','.$this->payList[$payCursor]['spec_key_name'] . "库存不足,请重新下单", 'result' => '']);
                }
            }
        }
        $this->Calculation();
    }

    /**
     * 计算购买购物车的商品
     * @param $cart_list
     * @throws TpshopException
     */
    public function payCart($cart_list){
        $this->payList = $cart_list;
        $goodsListCount = count($this->payList);
        if ($goodsListCount == 0) {
            throw new TpshopException('计算订单价格', 0, ['status' => -9, 'msg' => '你的购物车没有选中商品', 'result' => '']);
        }
        $this->Calculation();
    }

    /**
     * 计算购买商品表的商品
     * @param $goods_list
     * @throws TpshopException
     */
    public function payGoodsList($goods_list)
    {
        $goodsListCount = count($goods_list);
        if ($goodsListCount == 0) {
            throw new TpshopException('计算订单价格', 0, ['status' => -9, 'msg' => '你的购物车没有选中商品', 'result' => '']);
        }
        $discount = $this->getDiscount();
        for ($goodsCursor = 0; $goodsCursor < $goodsListCount; $goodsCursor++) {
            //优先使用member_goods_price，没有member_goods_price使用goods_price
            if(empty($goods_list[$goodsCursor]['member_goods_price'])){
                //积分商品不打折。因为是全积分商品打会员折扣，结算会出现负数
                if($goods_list[$goodsCursor]['exchange_integral'] > 0){
                    $goods_list[$goodsCursor]['member_goods_price'] = $goods_list[$goodsCursor]['goods_price'];
                }else{
                    $goods_list[$goodsCursor]['member_goods_price'] = $discount * $goods_list[$goodsCursor]['goods_price'];
                }

            }
        }
        $this->payList = $goods_list;
        $this->Calculation();
    }


    /**
     * 初始化计算
     */
    private function Calculation()
    {
        $goodsListCount = count($this->payList);
        for ($payCursor = 0; $payCursor < $goodsListCount; $payCursor++) {
            $this->payList[$payCursor]['goods_fee'] = $this->payList[$payCursor]['goods_num'] * $this->payList[$payCursor]['member_goods_price'];    // 小计
            $this->goodsPrice += $this->payList[$payCursor]['goods_fee']; // 商品总价
            if(array_key_exists('market_price',$this->payList[$payCursor])){
                $this->cutFee += $this->payList[$payCursor]['goods_num'] * ($this->payList[$payCursor]['market_price'] - $this->payList[$payCursor]['member_goods_price']);// 共节约
            }
            $this->totalNum += $this->payList[$payCursor]['goods_num'];
        }
        $this->orderAmount = $this->goodsPrice;
        $this->totalAmount = $this->goodsPrice;
    }

    /**
     * 设置用户ID
     * @throws TpshopException
     * @param $user_id
     */
    public function setUserId($user_id)
    {
        $this->userId = $user_id;
        $this->user = Db::name('users')->where(['user_id' => $this->userId])->find();
        if(empty($this->user)){
            throw new TpshopException("计算订单价格",0,['status' => -9, 'msg' => '未找到用户', 'result' => '']);
        }
    }

    /**
     * 使用积分
     * @throws TpshopException
     * @param $pay_points
     * @param $is_exchange|是否有使用积分兑换商品流程
     */
    public function usePayPoints($pay_points, $is_exchange = false)
    {
        if($pay_points > 0 && $this->orderAmount > 0){
            $point_rate = tpCache('shopping.point_rate'); //兑换比例
            if($is_exchange == false){
                $use_percent_point = tpCache('shopping.point_use_percent');     //最大使用限制: 最大使用积分比例, 例如: 为50时, 未50% , 那么积分支付抵扣金额不能超过应付金额的50%
                $min_use_limit_point = tpCache('shopping.point_min_limit'); //最低使用额度: 如果拥有的积分小于该值, 不可使用
                if($use_percent_point == 0){
                    throw new TpshopException("计算订单价格",0,['status' => -1, 'msg' => '该笔订单不能使用积分', 'result' => '']);
                }
                if($use_percent_point > 0 && $use_percent_point < 100){
                    //计算订单最多使用多少积分
                    $point_limit = $this->orderAmount * $point_rate * $use_percent_point;
                    if($pay_points > $point_limit){
                        throw new TpshopException("计算订单价格",0,['status' => -1, 'msg' => "该笔订单, 您使用的积分不能大于" . $point_limit, 'result' => '']);
                    }
                }
                if($pay_points > $this->user['pay_points']){
                    throw new TpshopException("计算订单价格",0,['status' => -5, 'msg' => "你的账户可用积分为:" . $this->user['pay_points'], 'result' => '']);
                }
                if ($min_use_limit_point > 0 && $pay_points < $min_use_limit_point) {
                    throw new TpshopException("计算订单价格",0,['status' => -1, 'msg' => "您使用的积分必须大于".$min_use_limit_point."才可以使用", 'result' => '']);
                }
                $order_amount_pay_point = floor($this->orderAmount * $point_rate);
                if($pay_points > $order_amount_pay_point){
                    $this->payPoints = $order_amount_pay_point;
                }else{
                    $this->payPoints = $pay_points;
                }
                $this->integralMoney = $this->payPoints / $point_rate;
                $this->orderAmount = $this->orderAmount - $this->integralMoney;
            }else{
                //积分兑换流程
                if($pay_points <= $this->user['pay_points']){
                    $this->payPoints = $pay_points;
                    $this->integralMoney = $pay_points / $point_rate;//总积分兑换成的金额
                }else{
                    $this->payPoints = 0;//需要兑换的总积分
                    $this->integralMoney = 0;//总积分兑换成的金额
                }
                $this->orderAmount = $this->orderAmount - $this->integralMoney;
            }

        }
    }

    /**
     * 使用余额
     * @throws TpshopException
     * @param $user_money
     */
    public function useUserMoney($user_money)
    {
        if($user_money > 0){
            if($user_money > $this->user['user_money']){
                throw new TpshopException("计算订单价格",0,['status' => -9, 'msg' =>  "你的账户可用余额为:" . $this->user['user_money'], 'result' => '']);
            }
            if($user_money > $this->orderAmount){
                $this->userMoney = $this->orderAmount;
                $this->orderAmount = 0;
            }else{
                $this->userMoney = $user_money;
                $this->orderAmount = $this->orderAmount - $this->userMoney;
            }
        }
    }

    /**
     * 减去应付金额
     * @param $cut_money
     */
    public function cutOrderAmount($cut_money){
        $this->orderAmount = $this->orderAmount - $cut_money;
    }

    /**
     * 使用优惠券
     * @param $coupon_id
     */
    public function useCouponById($coupon_id){
        if($coupon_id > 0){
            $couponList = new CouponList();
            $userCoupon = $couponList->where(['uid'=>$this->user['user_id'],'id'=>$coupon_id])->find();
            if($userCoupon){
                $where = [
                    'id'=>$userCoupon['cid'],
                    'status'=> ['in','1,3']
                ];
                $coupon = Db::name('coupon')->where($where)->find(); // 获取有效优惠券类型表
                if($coupon){
                    $this->couponId = $coupon_id;
                    $this->couponPrice = $coupon['money'];
                    $this->orderAmount = $this->orderAmount - $this->couponPrice;
                }
            }
        }
    }

    /**
     * 配送
     * @param $district_id
     * @throws TpshopException
     */
    public function delivery($district_id){
        if(empty($district_id)){
            throw new TpshopException("计算订单价格",0,['status'=>-1,'msg'=>'请填写收货信息','result'=>['']]);
        }
        $GoodsLogic = new GoodsLogic();
        $checkGoodsShipping = $GoodsLogic->checkGoodsListShipping($this->payList, $district_id);
        foreach($checkGoodsShipping as $shippingKey => $shippingVal){
            if($shippingVal['shipping_able'] != true){
                throw new TpshopException("计算订单价格",0,['status'=>-1,'msg'=>'订单中部分商品不支持对当前地址的配送请返回购物车修改','result'=>['goods_shipping'=>$checkGoodsShipping]]);
            }
        }
//        $freight_free = tpCache('shopping.freight_free'); // 全场满多少免运费/暂时不用
        $freight_free = 0;//每个地区可设置不同的免邮门槛
        if($this->goodsPrice < $freight_free || $freight_free == 0){
            $GoodsLogic->setGoodsPrice($this->goodsPrice);
            $this->shippingPrice = $GoodsLogic->getFreight($this->payList, $district_id);
            $this->orderAmount = $this->orderAmount + $this->shippingPrice;
            $this->totalAmount = $this->totalAmount + $this->shippingPrice;
        }else{
            $this->shippingPrice = 0;
        }
    }
    /**
     * 自提选择配送，不用管该地区是否支持配送，也不会计算运费
     */
    public function delivery_ziti($district_id){
        if(empty($district_id)){
            throw new TpshopException("计算订单价格",0,['status'=>-1,'msg'=>'请填写收货信息','result'=>['']]);
        }
        $this->shippingPrice = 0;
    }

    /**
     * 获取折扣
     * @return int
     */
    private function getDiscount()
    {
        if(empty($this->user['discount'])){
            return 1;
        }else{
            return $this->user['discount'];
        }
    }

    /**
     * 使用订单优惠
     */
    public function orderPromotion()
    {
        $time = time();
        $order_prom_where = ['type'=>['lt',2],'end_time'=>['gt',$time],'start_time'=>['lt',$time],'money'=>['elt',$this->goodsPrice]];
        $orderProm = Db::name('prom_order')->where($order_prom_where)->order('money desc')->find();
        if ($orderProm) {
            if ($orderProm['type'] == 0) {
                $expressionAmount = round($this->goodsPrice * $orderProm['expression'] / 100, 2);//满额打折
                $this->orderPromAmount = round($this->goodsPrice - $expressionAmount, 2);
                $this->orderPromId = $orderProm['id'];
            } elseif ($orderProm['type'] == 1) {
                $this->orderPromAmount = $orderProm['expression'];
                $this->orderPromId = $orderProm['id'];
            }
        }
        $this->orderAmount = $this->orderAmount - $this->orderPromAmount;
    }

    /**
     * 获取实际上使用的余额
     * @return int
     */
    public function getUserMoney()
    {
        return $this->userMoney;
    }

    /**
     * 获取订单总价
     * @return int
     */
    public function getTotalAmount()
    {
        return number_format($this->totalAmount, 2, '.', '');
    }

    /**
     * 获取订单应付金额
     * @return int
     */
    public function getOrderAmount()
    {
        return number_format($this->orderAmount, 2, '.', '');
    }

    /**
     * 获取实际上使用的积分抵扣金额
     * @return float
     */
    public function getIntegralMoney(){
        return $this->integralMoney;
    }

    /**
     * 获取实际上使用的积分
     * @return float|int
     */
    public function getPayPoints()
    {
        return $this->payPoints;
    }
    
    /**
     * 获取物流费
     * @return int
     */
    public function getShippingPrice()
    {
        return $this->shippingPrice;
    }

    /**
     *  获取优惠券费
     * @return int
     */
    public function getCouponPrice()
    {
        return $this->couponPrice;
    }

    /**
     * 商品总价
     * @return int
     */
    public function getGoodsPrice()
    {
        return $this->goodsPrice;
    }

    /**
     * 获取用户
     * @return mixed
     */
    public function getUser()
    {
        return $this->user;
    }

    public function getPayList()
    {
        return $this->payList;
    }

    public function getCouponId()
    {
        return $this->couponId;
    }

    public function getOrderPromAmount()
    {
        return $this->orderPromAmount;
    }
    public function getOrderPromId()
    {
        return $this->orderPromId;
    }

    public function toArray()
    {
        return [
            'shipping_price' => $this->shippingPrice,
            'coupon_price' => $this->couponPrice,
            'user_money' => round($this->userMoney, 2),
            'integral_money' => $this->integralMoney,
            'pay_points' => $this->payPoints,
            'order_amount' => round($this->orderAmount, 2),
            'total_amount' => round($this->totalAmount, 2),
            'goods_price' => round($this->goodsPrice, 2),
            'order_prom_amount' => $this->orderPromAmount,
        ];
    }
}