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
 * Author: 当燃
 * Date: 2015-09-09
 */
namespace app\admin\controller;
use app\admin\logic\OrderLogic;
use app\admin\logic\RefundLogic;
use app\common\library\Logs;
use app\common\logic\ActivityLogic;
use app\common\logic\PlaceOrder;
use app\common\logic\Pay;
use app\common\logic\WechatLogic;
use app\common\model\DistributeDivideLog;
use app\common\model\OrderGoods;
use app\common\model\UserModel;
use app\common\model\Users;
use app\common\model\VrOrderCode;
use app\common\util\TpshopException;
use app\task\controller\Distribute;
use think\AjaxPage;
use think\Config;
use think\Exception;
use think\Page;
use think\Db;

class Order extends Base {
    public  $order_status;
    public  $pay_status;
    public  $shipping_status;
    /*
     * 初始化操作
     */
    public function _initialize() {
        parent::_initialize();
        C('TOKEN_ON',false); // 关闭表单令牌验证
        $this->order_status = C('ORDER_STATUS');
        $this->pay_status = C('PAY_STATUS');
        $this->shipping_status = C('SHIPPING_STATUS');
        // 订单 支付 发货状态
        $this->assign('order_status',$this->order_status);
        $this->assign('pay_status',$this->pay_status);
        $this->assign('shipping_status',$this->shipping_status);
    }

    /*
     *订单首页
     */
    public function index(){
        $order_status = input('get.order_status/d');
        $pay_status = input('get.pay_status/d');
        $this->assign('order_status_value',$order_status);
        $this->assign('pay_status_value',$pay_status);
        return $this->fetch();
    }

    /*
     *Ajax首页
     */
    public function ajaxindex(){
        $orderLogic = new OrderLogic();
        $begin = $this->begin;
        $end = $this->end;
        // 搜索条件
        $condition = array();
        $keyType = I("keytype");
        $keywords = I('keywords','','trim');
        
        $consignee =  ($keyType && $keyType == 'consignee') ? $keywords : I('consignee','','trim');
        $consignee ? $condition['consignee'] = trim($consignee) : false;

        if($begin && $end){
        	$condition['add_time'] = array('between',"$begin,$end");
        }
        $condition['prom_type'] = array('not in',[5,6]);//除虚拟订单和拼团订单
        $order_sn = ($keyType && $keyType == 'order_sn') ? $keywords : I('order_sn') ;
        $order_sn ? $condition['order_sn'] = trim($order_sn) : false;
        
        I('order_status') != '' ? $condition['order_status'] = I('order_status') : false;
        I('pay_status') != '' ? $condition['pay_status'] = I('pay_status') : false;
        I('pay_code') != '' ? $condition['pay_code'] = I('pay_code') : false;
        I('shipping_status') != '' ? $condition['shipping_status'] = I('shipping_status') : false;
        I('user_id') ? $condition['user_id'] = trim(I('user_id')) : false;
        $sort_order = I('order_by','DESC').' '.I('sort');
        $count = M('order')->where($condition)->count();
        $Page  = new AjaxPage($count,20);
        $show = $Page->show();
        //获取订单列表
        $orderList = $orderLogic->getOrderList($condition,$sort_order,$Page->firstRow,$Page->listRows);
        $this->assign('orderList',$orderList);
        $this->assign('page',$show);// 赋值分页输出
        $this->assign('pager',$Page);
        return $this->fetch();
    }
    //虚拟订单
    public function virtual_list(){
    /*code_3虚拟订单业务逻辑*/
        $condition['prom_type'] = 5;
        $sort_order = 'order_id desc';
        $begin = $this->begin;
        $end = $this->end;
        if($begin && $end){
            $condition['add_time'] = array('between',"$begin,$end");
        }
        I('pay_status') && $condition['pay_status'] = I('pay_status');
        I('pay_code') != '' ? $condition['pay_code'] = I('pay_code') : false;

        $keyType = I("keytype");
        $keywords = I('keywords','','trim');
        $mobile =  ($keyType && $keyType == 'mobile') ? $keywords : I('mobile','','trim');
        $mobile ? $condition['mobile'] = trim($mobile) : false;
        $order_sn = ($keyType && $keyType == 'order_sn') ? $keywords : I('order_sn') ;
        $order_sn ? $condition['order_sn'] = trim($order_sn) : false;
        $orderLogic = new OrderLogic();
        $export = I('export');
        if($export == 1){
            $order_ids = I('order_ids');
            if($order_ids){
                $condition['order_id'] = ['in',$order_ids];
            }
            $orderList = M('order')->where($condition)->order($sort_order)->select();
            $strTable ='<table width="500" border="1">';
            $strTable .= '<tr>';
            $strTable .= '<td style="text-align:center;font-size:12px;width:120px;">订单编号</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="100">日期</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">接收人</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">购买人</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">订单金额</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">实际支付</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">支付方式</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">支付状态</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">使用期限</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">商品数量</td>';
            $strTable .= '<td style="text-align:center;font-size:12px;" width="*">商品信息</td>';
            $strTable .= '</tr>';

            foreach($orderList as $k=>$val){
                $strTable .= '<tr>';
                $strTable .= '<td style="text-align:center;font-size:12px;">&nbsp;'.$val['order_sn'].'</td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.date('Ymd',$val['add_time']).' </td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$val['mobile'].'</td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$val['consignee'].' </td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$val['goods_price'].'</td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$val['order_amount'].'</td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$val['pay_name'].'</td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$this->pay_status[$val['pay_status']].'</td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.date('Ymd',$val['shipping_time']).'</td>';
                $orderGoods = M('order_goods')->where('order_id='.$val['order_id'])->select();
                $strGoods="";
                $goods_num = 0;
                foreach($orderGoods as $goods){
                    $goods_num = $goods_num + $goods['goods_num'];
                    $strGoods .= "商品编号：".$goods['goods_sn']." 商品名称：".$goods['goods_name'];
                    if ($goods['spec_key_name'] != '') $strGoods .= " 规格：".$goods['spec_key_name'];
                    $strGoods .= "<br />";
                }
                unset($orderGoods);
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$goods_num.' </td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$strGoods.' </td>';
                $strTable .= '</tr>';
            }
            $strTable .='</table>';
            unset($orderList);
            downloadExcel($strTable,'order');
            exit();
        }
        $count = M('order')->where($condition)->count();
        $Page  = new Page($count,20);
        $show = $Page->show();
        $orderList = $orderLogic->getOrderList($condition,$sort_order,$Page->firstRow,$Page->listRows);
        //获取每个订单的商品列表
        $order_id_arr = get_arr_column($orderList, 'order_id');
        $user_id_arr = get_arr_column($orderList, 'user_id');
        if($order_id_arr){
            $goods_list = M('order_goods')->where("order_id in (".  implode(',', $order_id_arr).")")->select();
            $goods_arr = array();
            foreach ($goods_list as $v){
                $goods_arr[$v['order_id']][] =$v;
            }
            $this->assign('goodsArr',$goods_arr);
            $user_arr = M('users')->where("user_id in (".  implode(',', $user_id_arr).")")->getField('user_id,nickname');
            $this->assign('userArr',$user_arr);
        }
        $this->assign('begin', date('Y-m-d',$begin));
        $this->assign('end', date('Y-m-d',$end));
        $this->assign('orderList',$orderList);
        $this->assign('page',$show);
        $this->assign('total_count',$count);
        return $this->fetch();
	/*code_3虚拟订单业务逻辑*/
    }
    // 虚拟订单
    public function virtual_info(){
    /*code_3虚拟订单业务逻辑*/
        $order_id = I('order_id/d');
        // 获取操作表
        $order = M('order')->where(array('order_id'=>$order_id))->find();
        if($order['pay_status'] == 1){
            $vrorder = M('vr_order_code')->where(array('order_id'=>$order_id))->select();
            if(!empty($vrorder)){
                foreach($vrorder as $vrorderKey => $vrorderVal){
                    if($vrorderVal['vr_indate'] < time()&& $vrorder['vr_state'] == 0){ //看看有没有过期
                        $vrorder[$vrorderKey]['vr_state'] = 2;
                        Db::name('vr_order_code')->where(array('order_id'=>$order_id))->update(['vr_state'=>2]);
                        break;
                    }
                }
            }
            $this->assign('vrorder',$vrorder);
        }
        $order_goods = M('order_goods')->where(array('order_id'=>$order_id))->find();
        $order_goods['virtual_indate'] = M('goods')->where(array('goods_id'=>$order_goods['goods_id']))->getField('virtual_indate');
        $order['order_status_detail'] = C('ORDER_STATUS')[$order['order_status']];
        $this->assign('order',$order);
        $this->assign('order_goods',$order_goods);
        return $this->fetch();
	/*code_3虚拟订单业务逻辑*/
    }

    public function virtual_cancel(){
        $order_id = I('order_id/d');
        if(IS_POST){
            $admin_note = I('admin_note');
            $order = M('order')->where(array('order_id'=>$order_id))->find();
            if($order){
                $r = M('order')->where(array('order_id'=>$order_id))->save(array('order_status'=>3,'admin_note'=>$admin_note));
                if($r){
                    $orderLogic = new OrderLogic();
                    $orderLogic->orderActionLog($order_id,$admin_note, '取消订单');
                    $this->ajaxReturn(array('status'=>1,'msg'=>'操作成功'));
                }else{
                    $this->ajaxReturn(array('status'=>-1,'msg'=>'操作失败'));
                }
            }else{
                $this->ajaxReturn(array('status'=>-1,'msg'=>'订单不存在'));
            }
        }
        $order = M('order')->where(array('order_id'=>$order_id))->find();
        $this->assign('order',$order);
        return $this->fetch();
    }

    public function verify_code(){
        if(IS_POST){
            $vr_code = trim(I('vr_code'));
            if (!preg_match('/^[a-zA-Z0-9]{15,18}$/',$vr_code)) {
                $this->ajaxReturn(['status'=>0,'msg' => '兑换码格式错误，请重新输入']);
            }
            $vr_code_info = M('vr_order_code')->where(array('vr_code'=>$vr_code))->find();
            $order = M('order')->where(['order_id'=>$vr_code_info['order_id']])->field('order_status,order_sn,user_note')->find();
            if($order['order_status'] > 1 ){
                $this->ajaxReturn(['status'=>0,'msg' => '兑换码对应订单状态不符合要求']);
            }
            if(empty($vr_code_info)){
                $this->ajaxReturn(['status'=>0,'msg' => '该兑换码不存在']);
            }
            if ($vr_code_info['vr_state'] == '1') {
                $this->ajaxReturn(['status'=>0,'msg' => '该兑换码已被使用']);
            }
            if ($vr_code_info['vr_indate'] < time()) {
                $this->ajaxReturn(['status'=>0,'msg'=>'该兑换码已过期，使用截止日期为： '.date('Y-m-d H:i:s',$vr_code_info['vr_indate'])]);
            }
            if ($vr_code_info['refund_lock'] > 0) {//退款锁定状态:0为正常,1为锁定(待审核),2为同意
                $this->ajaxReturn(['status'=>0,'msg'=> '该兑换码已申请退款，不能使用']);
            }
            $update['vr_state'] = 1;
            $update['vr_usetime'] = time();
            M('vr_order_code')->where(array('vr_code'=>$vr_code))->save($update);
            //检查订单是否完成
            $condition = array();
            $condition['vr_state'] = 0;
            $condition['refund_lock'] = array('in',array(0,1));
            $condition['order_id'] = $vr_code_info['order_id'];
            $condition['vr_indate'] = array('gt',time());
            $vr_order = M('vr_order_code')->where($condition)->select();
            if(empty($vr_order)){
                $data['order_status'] = 2;  //此处不能直接为4，不然前台不能评论
                $data['confirm_time'] = time();
                $res = M('order')->where(['order_id'=>$vr_code_info['order_id']])->save($data);
                //判断是否是首单,上级分发优惠券
                if($res)(new ActivityLogic())->firstOrderCoupon($vr_code_info['user_id']);
                M('order_goods')->where(['order_id'=>$vr_code_info['order_id']])->save(['is_send'=>1]);  //把订单状态改为已收货
                //相应订单分成
                (new Distribute())->autoDistribute($order['order_sn']);
            }
            $order_goods = M('order_goods')->where(['order_id'=>$vr_code_info['order_id']])->find();
            if($order_goods){
                $result = [
                    'vr_code'=>$vr_code,
                    'order_goods'=>$order_goods,
                    'order'=>$order,
                    'goods_image'=>goods_thum_images($order_goods['goods_id'],240,240),
                ];
                $this->ajaxReturn(['status'=>1,'msg'=>'兑换成功','result'=>$result]);
            }else{
                $this->ajaxReturn(['status'=>0,'msg'=>'虚拟订单商品不存在']);
            }
        }
        return $this->fetch();
    }

    //虚拟订单临时支付方法，以后要删除
    public function generateVirtualCode(){
        $order_id = I('order_id/d');
        // 获取操作表
        $order = M('order')->where(array('order_id'=>$order_id))->find();
        update_pay_status($order['order_sn'], ['admin_id'=>session('admin_id'),'note'=>'订单付款成功']);
        $vr_order_code = Db::name('vr_order_code')->where('order_id',$order_id)->find();
        if(!empty($vr_order_code)){
            $this->success('成功生成兑换码', U('Order/virtual_info',['order_id'=>$order_id]), 1);
        }else{
            $this->error('生成兑换码失败', U('Order/virtual_info',['order_id'=>$order_id]), 1);
        }
    }

    /*
     * ajax 发货订单列表
    */
    public function ajaxdelivery(){
    	$condition = array();
    	I('consignee') ? $condition['consignee'] = trim(I('consignee')) : false;
    	I('order_sn') != '' ? $condition['order_sn'] = trim(I('order_sn')) : false;
    	$shipping_status = I('shipping_status');
    	$condition['shipping_status'] = empty($shipping_status) ? array('neq',1) : $shipping_status;
        $condition['order_status'] = array('in','1,2,4');
        $condition['prom_type'] = ['neq',5];
    	$count = M('order')->where($condition)->count();
    	$Page  = new AjaxPage($count,10);
    	//搜索条件下 分页赋值
    	foreach($condition as $key=>$val) {
            if(!is_array($val)){
                $Page->parameter[$key]   =   urlencode($val);
            }
    	}
    	$show = $Page->show();
    	$orderList = M('order')->where($condition)->limit($Page->firstRow.','.$Page->listRows)->order('add_time DESC')->select();
    	$this->assign('orderList',$orderList);
    	$this->assign('page',$show);// 赋值分页输出
    	$this->assign('pager',$Page);
    	return $this->fetch();
    }
    
    public function refund_order_list(){
    	I('consignee') ? $condition['consignee'] = trim(I('consignee')) : false;
    	I('order_sn') != '' ? $condition['order_sn'] = trim(I('order_sn')) : false;
    	I('mobile') != '' ? $condition['mobile'] = trim(I('mobile')) : false;
        $prom_type = input('prom_type');
        if($prom_type){
            $condition['prom_type'] = $prom_type;
        }
    	$condition['shipping_status'] = 0;
    	$condition['order_status'] = 3;
    	$condition['pay_status'] = array('gt',0);
    	$count = M('order')->where($condition)->count();
    	$Page  = new Page($count,10);
    	//搜索条件下 分页赋值
    	foreach($condition as $key=>$val) {
    		if(!is_array($val)){
    			$Page->parameter[$key]   =   urlencode($val);
    		}
    	}
    	$show = $Page->show();
    	$orderList = M('order')->where($condition)->limit($Page->firstRow.','.$Page->listRows)->order('add_time DESC')->select();
    	$this->assign('orderList',$orderList);
    	$this->assign('page',$show);// 赋值分页输出
    	$this->assign('pager',$Page);
    	return $this->fetch();
    }
    
    public function refund_order_info($order_id){
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$order_id]);
        $order =$orderObj->append(['full_address','orderGoods'])->toArray();
    	$this->assign('order',$order);
    	return $this->fetch();
    }

    //取消订单退款原路退回
    public function refund_order(){
        $data = I('post.');
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$data['order_id']]);
        $order =$orderObj->append(['full_address'])->toArray();
        if(!$order){
            $this->error('订单不存在或参数错误');
        }

        //退款成功发送短信提醒,并取消分成
        $func = function ($user_id) use ($orderObj) {
            if (!config('APP_DEBUG')) {
                $nicknameMobile = (new Users())->where('user_id', $user_id)->getField('nickname,mobile');
                if ($nicknameMobile) {
                    $nickname = array_keys($nicknameMobile)[0];
                    $mobile = array_values($nicknameMobile)[0];
                    $resp = sendSms('2', $mobile, ['name' => $nickname]);
                    if ($resp['status'] != 1) {
                        Logs::sentryLogs('发送短信失败');
                    }
                }
            }

            //取消分成
            try{
                (new DistributeDivideLog())->cancleDivideData($orderObj->order_sn);
            }catch(Exception $exception){
                Logs::sentryLogs($exception,['msg' => '取消分成失败，订单号：'.$orderObj->order_sn]);
            }
        };

        if($data['pay_status'] == 3){
            $refundLogic = new RefundLogic();
            if($data['refund_type'] == 1){
            	//取消订单退款退余额
                if($refundLogic->updateRefundOrder($order,1)){
                    $func($order['user_id']);
                    $this->success('成功退款到账户余额');
                }else{
                    $this->error('退款失败');
                }
            }
            if($data['refund_type']== 0){
                //取消订单支付原路退回
                if($order['pay_code'] == 'weixin' || $order['pay_code'] == 'alipay' || $order['pay_code'] == 'alipayMobile'){
		        /*code_4支付原路退回逻辑*/
                    $return_money = $order['order_amount'];
                    if($order['pay_code'] == 'weixin'){
                        include_once  PLUGIN_PATH."payment/weixin/weixin.class.php";
                        $payment_obj =  new \weixin();
                        $data = array('transaction_id'=>$order['transaction_id'],'total_fee'=>$order['order_amount'],'refund_fee'=>$return_money);
                        $result = $payment_obj->payment_refund($data);
                        if ($result['return_code'] == 'SUCCESS'  && $result['result_code'] == 'SUCCESS') {
                            if($refundLogic->updateRefundOrder($order)){
                                $func($order['user_id']);
                                $this->success('支付原路退款成功');
                            }else{
                                $func($order['user_id']);
                                $this->error('支付原路退款成功,余额支付部分退款失败');
                            }
                        }else{
                            $this->error('支付原路退款失败'.$result['return_msg']);
                        }
                    }else{
                        //修改该退款方式，使用支付宝应用退款
                        $alipayConfig = Config::get('alipay');
                        $refunDataForAlipay = [
                            'out_trade_no' => $order['order_sn'],
                            'refund_amount' => $order['order_amount'],
                        ];
                        $refundResult = \Yansongda\Pay\Pay::alipay($alipayConfig)->refund($refunDataForAlipay);
                        if ($refundResult->code == 10000 && $refundResult->fund_change == 'Y') {
                            if($refundLogic->updateRefundOrder($order)){
                                $func($order['user_id']);
                                $this->success('支付原路退款成功');
                            }else{
                                $func($order['user_id']);
                                $this->error('支付原路退款成功,余额支付部分退款失败');
                            }
                        }
                        $this->error('支付原路退款失败,请联系开发人员');
                    }
		        /*code_4支付原路退回逻辑*/
                }else{
                    $this->error('该订单支付方式不支持在线退回');
                }
            }
        }else{
            M('order')->where(array('order_id'=>$order['order_id']))->save($data);
            $this->success('拒绝退款操作成功');
        }
    }

    /**
     * 订单详情
     * @param $order_id
     * @return mixed
     */
    public function detail($order_id){
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$order_id]);
        $order =$orderObj->append(['full_address','orderGoods','adminOrderButton'])->toArray();
        $orderGoods =$order['orderGoods'];
        $express = Db::name('delivery_doc')->where("order_id" , $order_id)->select();  //发货信息（可能多个）
        $user = Db::name('users')->where(['user_id'=>$order['user_id']])->find();
        //砍价商品砍去的价格
        $cutPrice = Db::table('cf_bargain_found')->field('goods_price,price')->where('order_id',$order['order_id'])->find();
        $order['cut_price'] = $cutPrice['goods_price']-$cutPrice['price'];
        //该笔订单返利信息
        $rebate = $this->getOrderRebate($order['order_sn']);
        $this->assign('rebate',$rebate);//返利表
        $this->assign('order',$order);
        $this->assign('user',$user);
        $split = count($orderGoods) >1 ? 1 : 0;
        foreach ($orderGoods as $val){
        	if($val['goods_num']>1){
        		$split = 1;
        	}
        }
        $this->assign('split',$split);
        $this->assign('express',$express);
        return $this->fetch();
    }

    /**
     * 获取订单操作记录
     */
    public function getOrderAction(){
        $order_id = input('order_id/d',0);
        $order_id <= 0 && $this->ajaxReturn(['status'=>1,'msg'=>'参数错误！！']);
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$order_id]);
        $order = $orderObj->toArray();
        // 获取操作记录
        $action_log = Db::name('order_action')->where(['order_id'=>$order_id])->order('log_time desc')->select();
        $admins = M("admin")->getField("admin_id , user_name", true);
        $user = M("users")->field('user_id,nickname')->where(['user_id'=>$order['user_id']])->find();
        //查找用户昵称
        foreach ($action_log as $k => $v){
            if ($v['action_user'] == 0){
                $action_log["$k"]['action_user_name'] = '用户:'.$user['nickname'];
            }else{
                $action_log["$k"]['action_user_name'] = '管理员:'.$admins[$v['action_user']];
            }
            $action_log["$k"]["log_time"] = date('Y-m-d H:i:s',$v['log_time']);
            $action_log["$k"]["order_status"] = $this->order_status[$v['order_status']];
            $action_log["$k"]["pay_status"] = $this->pay_status[$v['pay_status']];
            $action_log["$k"]["shipping_status"] = $this->shipping_status[$v['shipping_status']];
        }
        $this->ajaxReturn(['status'=>1,'msg'=>'参数错误！！','data'=>$action_log]);
    }
    
    

    /*
     * 拆分订单
     */
    public function split_order(){
    	$order_id = I('order_id');
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$order_id]);
        $order =$orderObj->append(['full_address','orderGoods'])->toArray();
    	if($order['shipping_status'] != 0){
    		$this->error('已发货订单不允许编辑');
    		exit;
    	}
    	$orderGoods = $order['orderGoods'];
    	if(IS_POST){
    		//################################先处理原单剩余商品和原订单信息
    		$old_goods = I('old_goods/a');
            $oldArr = [];

    		foreach ($orderGoods as $val){
    			if(empty($old_goods[$val['rec_id']])){
    				M('order_goods')->where("rec_id=".$val['rec_id'])->delete();//删除商品
    			}else{
    				//修改商品数量
    				if($old_goods[$val['rec_id']] != $val['goods_num']){
    					$val['goods_num'] = $old_goods[$val['rec_id']];
    					M('order_goods')->where("rec_id=".$val['rec_id'])->save(array('goods_num'=>$val['goods_num']));
    				}
    				$oldArr[] = $val;//剩余商品
    			}
    			$all_goods[$val['rec_id']] = $val;//所有商品信息
    		}
            $oldPay = new Pay();
            try{
                $oldPay->setUserId($order['user_id']);
                $oldPay->payOrder($oldArr);
                $oldPay->delivery($order['district']);
                $oldPay->orderPromotion();
            } catch (TpshopException $t) {
                $error = $t->getErrorArr();
                $this->error($error['msg']);
            }
    		//修改订单费用
    		$res['goods_price']    = $oldPay->getGoodsPrice(); // 商品总价
    		$res['order_amount']   = $oldPay->getOrderAmount(); // 应付金额
    		$res['total_amount']   = $oldPay->getTotalAmount(); // 订单总价
//    		M('order')->where("order_id=".$order_id)->save($res);
			//################################原单处理结束

    		//################################新单处理
    		for($i=1;$i<20;$i++){
                $temp = $this->request->param($i.'_old_goods/a');
    			if(!empty($temp)){
    				$split_goods[] = $temp;
    			}
    		}

    		foreach ($split_goods as $key=>$vrr){
    			foreach ($vrr as $k=>$v){
    				$all_goods[$k]['goods_num'] = $v;
    				$brr[$key][] = $all_goods[$k];
    			}
    		}

    		foreach($brr as $goods){
                $newPay = new Pay();
                try{
                    $newPay->setUserId($order['user_id']);
                    $newPay->payGoodsList($goods);
                    $newPay->delivery($order['district']);
                    $newPay->orderPromotion();
                } catch (TpshopException $t) {
                    $error = $t->getErrorArr();
                    $this->error($error['msg']);
                }
    			$new_order = $order;
    			$new_order['order_sn'] = date('YmdHis').mt_rand(1000,9999);
    			$new_order['parent_sn'] = $order['order_sn'];
    			//修改订单费用
    			$new_order['goods_price']    = $newPay->getGoodsPrice(); // 商品总价
    			$new_order['order_amount']   = $newPay->getOrderAmount(); // 应付金额
    			$new_order['total_amount']   = $newPay->getTotalAmount(); // 订单总价
    			$new_order['add_time'] = time();
    			unset($new_order['order_id']);
    			$new_order_id = DB::name('order')->insertGetId($new_order);//插入订单表
    			foreach ($goods as $vv){
    				$vv['order_id'] = $new_order_id;
    				unset($vv['rec_id']);
    				$nid = M('order_goods')->add($vv);//插入订单商品表
    			}
    		}
    		//################################新单处理结束
    		$this->success('操作成功',U('Admin/Order/detail',array('order_id'=>$order_id)));
            exit;
    	}

    	foreach ($orderGoods as $val){
    		$brr[$val['rec_id']] = array('goods_num'=>$val['goods_num'],'goods_name'=>getSubstr($val['goods_name'], 0, 35).$val['spec_key_name']);
    	}
    	$this->assign('order',$order);
    	$this->assign('goods_num_arr',json_encode($brr));
    	$this->assign('orderGoods',$orderGoods);
    	return $this->fetch();
    }

    /*
     * 价钱修改
     */
    public function editprice($order_id){
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$order_id]);
        $order = $orderObj->toArray();
        $this->editable($order);
        if(IS_POST){
        	$admin_id = session('admin_id');
            if(empty($admin_id)){
                $this->error('非法操作');
                exit;
            }
            $update['discount'] = I('post.discount');
            $update['shipping_price'] = I('post.shipping_price');
			$update['order_amount'] = $order['order_amount']+$update['shipping_price']-$update['discount']-$order['user_money']-$order['integral_money']-$order['coupon_price']-$order['shipping_price'];
            $row = M('order')->where(array('order_id'=>$order_id))->save($update);
            if(!$row){
                $this->success('没有更新数据',U('Admin/Order/editprice',array('order_id'=>$order_id)));
            }else{
                $this->success('操作成功',U('Admin/Order/detail',array('order_id'=>$order_id)));
            }
            exit;
        }
        $this->assign('order',$order);
        return $this->fetch();
    }

    /**
     * 订单删除
     * @param int $id 订单id
     */
    public function delete_order(){
        $order_id = I('post.order_id/d',0);
    	$orderLogic = new OrderLogic();
    	$del = $orderLogic->delOrder($order_id);
        $this->ajaxReturn($del);
    }

    /**
     * 订单取消付款
     * @param $order_id
     * @return mixed
     */
    public function pay_cancel($order_id){
    	if(I('remark')){
    		$data = I('post.');
    		$note = array('退款到用户余额','已通过其他方式退款','不处理，误操作项');
    		if($data['refundType'] == 0 && $data['amount']>0){
    			accountLog($data['user_id'], $data['amount'], 0,  '退款到用户余额');
    		}
    		$orderLogic = new OrderLogic();
            $orderLogic->orderProcessHandle($data['order_id'],'pay_cancel');
    		$d = $orderLogic->orderActionLog($data['order_id'],'支付取消',$data['remark'].':'.$note[$data['refundType']]);
    		if($d){
    			exit("<script>window.parent.pay_callback(1);</script>");
    		}else{
    			exit("<script>window.parent.pay_callback(0);</script>");
    		}
    	}else{
    		$order = M('order')->where("order_id=$order_id")->find();
    		$this->assign('order',$order);
    		return $this->fetch();
    	}
    }

    /**
     * 订单打印
     * @param string $id
     * @return mixed
     */
    public function order_print($id=''){
        if($id){
            $order_id = $id;
        }else{
            $order_id = I('order_id');
        }
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$order_id]);
        $order =$orderObj->append(['full_address','orderGoods'])->toArray();
        $order['province'] = getRegionName($order['province']);
        $order['city'] = getRegionName($order['city']);
        $order['district'] = getRegionName($order['district']);
        $order['full_address'] = $order['province'].' '.$order['city'].' '.$order['district'].' '. $order['address'];
        if($id){
            return $order;
        }else{
            //砍价商品砍去的价格
            $cutPrice = Db::table('cf_bargain_found')->field('goods_price,price')->where('order_id',$order['order_id'])->find();
            $order['cut_price'] = $cutPrice['goods_price']-$cutPrice['price'];
            $shop = tpCache('shop_info');
            $this->assign('invoiceNo',$this->getInvoiceNo($order['order_sn']));
            $this->assign('order',$order);
            $this->assign('shop',$shop);
            $template = I('template','picking');
            return $this->fetch($template);
        }
    }

    /*
     *批量打印发货单
     */
    public function delivery_print(){
        $ids =input('print_ids');
        $order_ids=trim($ids,',');
        $orderModel= new \app\common\model\Order();
        $orderObj = $orderModel->whereIn('order_id',$order_ids)->select();
        if ($orderObj){
            $order = $orderObj->append(['orderGoods','full_address'])->toArray();
        }
        $shop = tpCache('shop_info');
        $this->assign('order',$order);
        $this->assign('shop',$shop);
        $template = I('template','print');
        return $this->fetch($template);
    }

    /**
     * 快递单打印
     */
    public function shipping_print($id=''){
        if($id){
            $order_id = $id;
        }else{
            $order_id = I('get.order_id');
        }
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$order_id]);
        $order =$orderObj->append(['full_address'])->toArray();
        //查询是否存在订单及物流
        $shipping = Db::name('shipping')->where('shipping_code',$order['shipping_code'])->find();
        if(!$shipping){
        	$this->error('快递公司不存在');
        }
		if(empty($shipping['template_html'])){
			$this->error('请设置'.$shipping['shipping_name'].'打印模板');
		}
        $shop = tpCache('shop_info');//获取网站信息
        $shop['province'] = empty($shop['province']) ? '' : getRegionName($shop['province']);
        $shop['city'] = empty($shop['city']) ? '' : getRegionName($shop['city']);
        $shop['district'] = empty($shop['district']) ? '' : getRegionName($shop['district']);

        $order['province'] = getRegionName($order['province']);
        $order['city'] = getRegionName($order['city']);
        $order['district'] = getRegionName($order['district']);
        $template_var = array("发货点-名称", "发货点-联系人", "发货点-电话", "发货点-省份", "发货点-城市",
        		 "发货点-区县", "发货点-手机", "发货点-详细地址", "收件人-姓名", "收件人-手机", "收件人-电话",
        		"收件人-省份", "收件人-城市", "收件人-区县", "收件人-邮编", "收件人-详细地址", "时间-年", "时间-月",
        		"时间-日","时间-当前日期","订单-订单号", "订单-备注","订单-配送费用");
        $content_var = array($shop['store_name'],$shop['contact'],$shop['phone'],$shop['province'],$shop['city'],
        	$shop['district'],$shop['phone'],$shop['address'],$order['consignee'],$order['mobile'],$order['phone'],
        	$order['province'],$order['city'],$order['district'],$order['zipcode'],$order['address'],date('Y'),date('M'),
        	date('d'),date('Y-m-d'),$order['order_sn'],$order['admin_note'],$order['shipping_price'],
        );
        $shipping['template_html_replace'] = str_replace($template_var, $content_var, $shipping['template_html']);
        if($id){
            return $shipping;
        }else{
            $shippings[0]=$shipping;
            $this->assign('shipping',$shippings);
            return $this->fetch("print_express");
        }

    }

    /*
     *批量打印快递单
     */
    public function shipping_print_batch(){
        $ids=I('post.ids3');
        $ids=substr($ids,0,-1);
        $ids=explode(',', $ids);
        if(!is_numeric($ids[0])){
            unset($ids[0]);
        }

        $shippings=array();
        foreach ($ids as $k => $v) {
            $shippings[$k]=$this->shipping_print($v);
        }
        $this->assign('shipping',$shippings);
        return $this->fetch("print_express");
    }

    /**
     * 生成发货单
     */
    public function deliveryHandle(){
        $orderLogic = new OrderLogic();
		$data = I('post.');
		$res = $orderLogic->deliveryHandle($data);
		if($res['status'] == 1){
			if($data['send_type'] == 2 && !empty($res['printhtml'])){
				$this->assign('printhtml',$res['printhtml']);
				return $this->fetch('print_online');
			}
			$this->success('操作成功',U('Admin/Order/delivery_info',array('order_id'=>$data['order_id'])));
		}else{
			$this->error($res['msg'],U('Admin/Order/delivery_info',array('order_id'=>$data['order_id'])));
		}
    }

    public function delivery_info($id=''){
        if($id){
           $order_id=$id; 
        }else{
           $order_id = I('order_id');
        }

    	$orderGoodsMdel = new OrderGoods();
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel->where(['order_id'=>$order_id])->find();
        $order =$orderObj->append(['full_address'])->toArray();
    	$orderGoods = $orderGoodsMdel::all(['order_id'=>$order_id,'is_send'=>['lt',2]]);
        if($id){
            if(!$orderGoods){
                $this->error('所选订单有商品已完成退货或换货');//已经完成售后的不能再发货
            }
        }else{
            if(!$orderGoods){
                $this->error('此订单商品已完成退货或换货');//已经完成售后的不能再发货  
            }
        }

        if($id){ 
            $order['orderGoods']=$orderGoods;
            $order['goods_num']=count($orderGoods);
            return $order;
        }else{
            $delivery_record = M('delivery_doc')->alias('d')->join('__ADMIN__ a','a.admin_id = d.admin_id')->where('d.order_id='.$order_id)->select();
            if($delivery_record){
                $order['invoice_no'] = $delivery_record[count($delivery_record)-1]['invoice_no'];
            }
            $this->assign('order',$order);
            $this->assign('orderGoods',$orderGoods);
            $this->assign('delivery_record',$delivery_record);//发货记录
            $shipping_list = Db::name('shipping')->field('shipping_name,shipping_code')->where('')->select();
            $this->assign('shipping_list',$shipping_list);
            $express_switch = tpCache('express.express_switch');
            $this->assign('express_switch',$express_switch);
            return $this->fetch();    
        }
    }

    /**
     * 发货单列表
     */
    public function delivery_list(){
        return $this->fetch();
    }

    /*
    *批量发货
    */
    public function delivery_batch(){
		/*code_18订单批量发货*/
        $ids=I('post.ids');
        $ids=substr($ids,0,-1);
        $ids=explode(',', $ids);
        if(!is_numeric($ids[0])){
            unset($ids[0]);
        }

        $orders=array();
        foreach ($ids as $k => $v) {
           $orders[$k]=$this->delivery_info($v);
        }
       
        $shipping_list = Db::name('shipping')->field('shipping_name,shipping_code')->where('')->select();
        $this->assign('orders',$orders);
        $this->assign('num',count($ids));
        $this->assign('shipping_list',$shipping_list);
        return $this->fetch();
		/*code_18订单批量发货*/
    }

    /*
    *批量发货处理 
    */
    public function delivery_batch_handle(){
		/*code_18订单批量发货*/
        $data=I('post.');
        $param=$data['order'];
        //参数拼装
        foreach ($param as $k => $v) {
            $param[$k]['shipping_name']=$data['shipping_name'];
            $param[$k]['shipping_code']=$data['shipping_code'];
            $param[$k]['note']='';//好像是必填项 没有此字段会数据库报错 先赋个空值
        }

        $orderLogic = new OrderLogic();
        foreach ($param as $k => $v) {
            $res=$orderLogic->deliveryHandle($v);
            if(!res){
                $this->error('操作失败', U('Order/delivery_list'));
                exit();
            }
        }
        $this->success('操作成功',U('Order/delivery_list'));
		/*code_18订单批量发货*/
    }

    /**
     * 删除某个退换货申请
     */
    public function return_del(){
        $id = I('get.id');
        M('return_goods')->where("id = $id")->delete();
        $this->success('成功删除!');
    }

    /**
     * 退换货操作
     */
    public function return_info()
    {
        $return_id = I('id');
        $return_goods = M('return_goods')->where("id= $return_id")->find();
        !$return_goods && $this->error('非法操作!');
        $user = M('users')->where(['user_id' => $return_goods['user_id']])->find();
        $order = M('order')->where(array('order_id'=>$return_goods['order_id']))->find();
        $order['goods'] = M('order_goods')->where(['rec_id' => $return_goods['rec_id']])->find();
        $return_goods['delivery'] = unserialize($return_goods['delivery']);  //订单的物流信息，服务类型为换货会显示
        $return_goods['seller_delivery'] = unserialize($return_goods['seller_delivery']);  //订单的物流信息，服务类型为换货会显示
        if($return_goods['imgs']) $return_goods['imgs'] = explode(',', $return_goods['imgs']);
        $this->assign('id',$return_id); // 用户
        $this->assign('user',$user); // 用户
        $this->assign('return_goods',$return_goods);// 退换货
        $this->assign('order',$order);//退货订单信息
        $this->assign('return_type',C('RETURN_TYPE'));//退货订单信息
        $this->assign('refund_status',C('REFUND_STATUS'));
        return $this->fetch();
    }

    /**
     *修改退货状态
     */
    public function checkReturniInfo()
    {
        $orderLogic = new OrderLogic();
        $post_data = I('post.');
        $return_goods = Db::name('return_goods')->where(['id'=>$post_data['id']])->find();
        !$return_goods && $this->ajaxReturn(['status'=>-1,'msg'=>'非法操作!']);
        $type_msg = C('RETURN_TYPE');
        $status_msg = C('REFUND_STATUS');
        switch ($post_data['status']){
            case 1 :$post_data['checktime'] = time();break;
            case 3 :$post_data['receivetime'] = time();break;  //卖家收货时间
            default;
        }
        if($return_goods['type'] > 0  && $post_data['status'] == 4){
            $post_data['seller_delivery']['express_time'] = date('Y-m-d H:i:s');
            $post_data['seller_delivery'] = serialize($post_data['seller_delivery']); //换货的物流信息
            Db::name('order_goods')->where(['rec_id'=>$return_goods['rec_id']])->save(['is_send'=>2]);
        }
        $note ="退换货:{$type_msg[$return_goods['type']]}, 状态:{$status_msg[$post_data['status']]},处理备注：{$post_data['remark']}";
        $result = M('return_goods')->where(['id'=>$post_data['id']])->save($post_data);
        if($result && $post_data['status']==1)
        {
            //审核通过才更改订单商品状态，进行退货，退款时要改对应商品修改库存
            $order = \app\common\model\Order::get($return_goods['order_id']);
            $commonOrderLogic = new \app\common\logic\OrderLogic();
            $commonOrderLogic->alterReturnGoodsInventory($order,$return_goods['rec_id']); //审核通过，恢复原来库存
            if($return_goods['type'] < 2){
                $orderLogic->disposereRurnOrderCoupon($return_goods); // 是退货可能要处理优惠券
            }
            //取消分成
            if ($return_goods['type'] == 0 || $return_goods['type'] == 1) {
                //取消分成
                try{
                    (new DistributeDivideLog())->cancleDivideData($order->order_sn);
                }catch(Exception $exception){
                    Logs::sentryLogs($exception,['msg' => '取消分成失败，订单号：'.$order->order_sn]);
                }
            }
        }
        $orderLogic->orderActionLog($return_goods['order_id'],'退换货',$note);

        //发送模板消息
        $orderInfo = (new \app\common\model\Order())->alias('a')
            ->join(['tp_order_goods' => 'b'],'a.order_id = b.order_id','LEFT')
            ->where('a.order_id',$return_goods['order_id'])->select()->toArray();

        $result = (new WechatLogic())->sendTemplateMsgOnReturnOrderSuccessNotReturnGoods($orderInfo,$return_goods,$post_data);

        if ($result['status'] != 1) {
            Logs::sentryLogs('发送成功申请取消订单模板消息失败');
        }

        $this->ajaxReturn(['status'=>1,'msg'=>'修改成功','url'=>'']);
    }

    //售后退款原路退回
    public function refund_back(){
    	$return_id = I('id');
        $return_goods = M('return_goods')->where("id= $return_id")->find();
    	$rec_goods = M('order_goods')->where(array('order_id'=>$return_goods['order_id'],'goods_id'=>$return_goods['goods_id']))->find();
    	$order = M('order')->where(array('order_id'=>$rec_goods['order_id']))->find();

        //退款成功发送短信提醒
        $func = function ($user_id) {
            $nicknameMobile = (new Users())->where('user_id',$user_id)->getField('nickname,mobile');
            if ($nicknameMobile) {
                $nickname = array_keys($nicknameMobile)[0];
                $mobile = array_values($nicknameMobile)[0];
                $resp = sendSms('2',$mobile,['name' => $nickname]);
                if($resp['status'] != 1){
                    Logs::sentryLogs('发送短信失败');
                }
            }
        };

    	if($order['pay_code'] == 'weixin' || $order['pay_code'] == 'alipay' || $order['pay_code'] == 'alipayMobile'){
    		$orderLogic = new OrderLogic();
    		$return_goods['refund_money'] = $orderLogic->getRefundGoodsMoney($return_goods);
            $refundLogic = new RefundLogic();
    		if($order['pay_code'] == 'weixin'){
    			include_once  PLUGIN_PATH."payment/weixin/weixin.class.php";
    			$payment_obj =  new \weixin();
    			$data = array('transaction_id'=>$order['transaction_id'],'total_fee'=>$order['order_amount'],'refund_fee'=>$return_goods['refund_money']);

    			$result = $payment_obj->payment_refund($data);
    			if($result['return_code'] == 'SUCCESS'  && $result['result_code'] == 'SUCCESS'){
    				$refundLogic->updateRefundGoods($return_goods['rec_id']);//订单商品售后退款
                    if (!config('APP_DEBUG')) {
                        $func($order['user_id']);
                    }
    				return $this->ajaxReturn(['status' => 1,'msg' => '退款成功']);
    			}else{
                    return $this->ajaxReturn(['status' => -1,'msg' => $result['err_code_des']]);
    			}
    		}else{
                //修改该退款方式，使用支付宝应用退款
                $alipayConfig = Config::get('alipay');
                $refunDataForAlipay = [
                    'out_trade_no' => $order['order_sn'],
                    'refund_amount' => $order['order_amount'],
                ];
                $refundResult = \Yansongda\Pay\Pay::alipay($alipayConfig)->refund($refunDataForAlipay);
                if ($refundResult->code == 10000 && $refundResult->fund_change == 'Y') {
                    if($refundLogic->updateRefundOrder($order)){
                        $func($order['user_id']);
                        return $this->ajaxReturn(['status' => 1,'msg' => '支付原路退款成功']);
                    }else{
                        $func($order['user_id']);
                        return $this->ajaxReturn(['status' => -1,'msg' => '支付原路退款成功,余额支付部分退款失败']);
                    }
                }
                return $this->ajaxReturn(['status' => -1,'msg' => '支付原路退款失败,请联系开发人员']);
    		}
    	}else{
            return $this->ajaxReturn(['status' => -1,'msg' => '该订单支付方式不支持在线退回']);
    	}
    }

    /**
     * 退货，余额+积分支付
     * 有用三方金额支付的不走这个方法
     */
    public function refund_balance(){
		$data = I('POST.'); 
		$return_goods = M('return_goods')->where(array('rec_id'=>$data['rec_id']))->find();
		if(empty($return_goods)) $this->ajaxReturn(['status'=>0,'msg'=>"参数有误"]);
		$refundLogic = new RefundLogic();
		$refundLogic->updateRefundGoods($data['rec_id'],1);//售后商品退款
		$this->ajaxReturn(['status'=>1,'msg'=>"操作成功",'url'=>U("Admin/Order/return_list")]);
		
    }

    /**
     * 管理员生成申请退货单
     */
    public function add_return_goods()
   {
            $order_id = I('order_id');
            $goods_id = I('goods_id');

            $return_goods = M('return_goods')->where("order_id = $order_id and goods_id = $goods_id")->find();
            if(!empty($return_goods))
            {
                $this->error('已经提交过退货申请!',U('Admin/Order/return_list'));
                exit;
            }
            $order = M('order')->where("order_id = $order_id")->find();

            $data['order_id'] = $order_id;
            $data['order_sn'] = $order['order_sn'];
            $data['goods_id'] = $goods_id;
            $data['addtime'] = time();
            $data['user_id'] = $order[user_id];
            $data['remark'] = '管理员申请退换货'; // 问题描述
            M('return_goods')->add($data);
            $this->success('申请成功,现在去处理退货',U('Admin/Order/return_list'));
            exit;
    }

    /**
     * 订单操作
     * @param $id
     */
    public function order_action(){    	
        $orderLogic = new OrderLogic();
        $action = I('get.type');
        $order_id = I('get.order_id');
        if($action && $order_id){
            if($action !=='pay'){
                $convert_action= C('CONVERT_ACTION')["$action"];
                $res = $orderLogic->orderActionLog($order_id,$convert_action,I('note'));
            }
        	 $a = $orderLogic->orderProcessHandle($order_id,$action,array('note'=>I('note'),'admin_id'=>0));
        	 if($res !== false && $a !== false){
                 if ($action == 'remove') {
                     $this->ajaxReturn(['status' => 1, 'msg' => '操作成功', 'url' => U('Order/index')]);
                 }
                 $this->ajaxReturn(['status' => 1,'msg' => '操作成功','url' => U('Order/detail',array('order_id'=>$order_id))]);
        	 }else{
                 if ($action == 'remove') {
                     $this->ajaxReturn(['status' => 0, 'msg' => '操作失败', 'url' => U('Order/index')]);
                 }
        	 	$this->ajaxReturn(['status' => 0,'msg' => '操作失败','url' => U('Order/index')]);
        	 }
        }else{
        	$this->ajaxReturn(['status' => 0,'msg' => '参数错误','url' => U('Order/index')]);
        }
    }
    
    public function order_log(){
    	$order_sn = I('order_sn');
    	$condition = array();
        $begin = $this->begin;
        $end = $this->end;
        $condition['log_time'] = array('between',"$begin,$end");
        if($order_sn){   //搜索订单号
            $order_id_arr = Db::name('order')->where(['order_sn' => $order_sn])->getField('order_id',true);
            $order_ids =implode(',',$order_id_arr);
            $condition['order_id']=['in',$order_ids];
            $this->assign('order_sn',$order_sn);
        }

    	$admin_id = I('admin_id');
		if($admin_id >0 ){
			$condition['action_user'] = $admin_id;
		}
    	$count = M('order_action')->where($condition)->count();
    	$Page = new Page($count,20);

    	foreach($condition as $key=>$val) {
    		$Page->parameter[$key] = urlencode($begin.'_'.$end);
    	}
    	$show = $Page->show();
    	$list = M('order_action')->where($condition)->order('action_id desc')->limit($Page->firstRow.','.$Page->listRows)->select();
        $orderIds = [];
        foreach ($list as $log) {
            if (!$log['action_user']) {
                $orderIds[] = $log['order_id'];
            }
        }
        if ($orderIds) {
            $users = M("users")->alias('u')->join('__ORDER__ o', 'o.user_id = u.user_id')->getField('o.order_id,u.nickname');
        }
        $this->assign('users',$users);
    	$this->assign('list',$list);
    	$this->assign('pager',$Page);
    	$this->assign('page',$show);   	
    	$admin = M('admin')->getField('admin_id,user_name');
    	$this->assign('admin',$admin);    	
    	return $this->fetch();
    }

    /**
     * 检测订单是否可以编辑
     * @param $order
     */
    private function editable($order){
        if($order['shipping_status'] != 0){
            $this->error('已发货订单不允许编辑');
            exit;
        }
        return;
    }

    public function export_order()
    {
    	//搜索条件
        $consignee = I('consignee');
        $order_sn =  I('order_sn');
        $order_status = I('order_status');
        $order_ids = I('order_ids');
        $prom_type = I('prom_type'); //订单类型
        $where = array();//搜索条件
        if($consignee){
            $where['consignee'] = ['like','%'.$consignee.'%'];
        }
        if($order_sn){
            $where['order_sn'] = $order_sn;
        }
        $prom_type != '' ? $where['prom_type'] = $prom_type : $where['prom_type'] = ['not in',[5,6]];//不要虚拟订单,拼团订单
        if($order_status){
            $where['order_status'] = $order_status;
        }
        $begin = $this->begin;
        $end = $this->end;
        $where['add_time'] = ['between',[$begin, $end]];
        if($order_ids){
            $where['order_id'] = ['in', $order_ids];
        }
        $orderList = Db::name('order')
            ->field("*,FROM_UNIXTIME(add_time,'%Y-%m-%d') as create_time")
            ->where($where)
            ->order('order_id')
            ->select();
        for ($i=0;$i<count($orderList);$i++){
            $orderList[$i]['act_type'] = Db::table('cf_bargain_found')
                ->alias('a')
                ->join(['cf_bargain_activity'=>'b'],'a.bargain_id=b.id')
                ->where('a.order_id',$orderList[$i]['order_id'])
                ->getField('b.act_type');
            if ($orderList[$i]['prom_type']==7 && $orderList[$i]['act_type'] == 0){
                $orderList[$i]['act_type']='砍价免费拿';
            }elseif ($orderList[$i]['prom_type']==7 && $orderList[$i]['act_type'] == 1){
                $orderList[$i]['act_type']='砍价底价购';
            }elseif($orderList[$i]['prom_type']==4){
                $orderList[$i]['act_type']='预售订单';
            }else{
                $orderList[$i]['act_type']='普通订单';
            }
            $orderList[$i]['rebate'] = $this->getOrderRebate($orderList[$i]['order_sn'])['sum'];//该笔订单返利合计
        }
        $strTable ='<table width="500" border="1">';
    	$strTable .= '<tr>';
    	$strTable .= '<td style="text-align:center;font-size:12px;width:120px;">订单编号</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="100">日期</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">收货人</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">收货地址</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">电话</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">订单类型</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">订单金额</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">实际支付</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">合计返利</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">支付方式</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">支付状态</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">发货状态</td>';
        $strTable .= '<td style="text-align:center;font-size:12px;width:120px;">物流单号</td>';
        $strTable .= '<td style="text-align:center;font-size:12px;" width="*">商品数量</td>';
    	$strTable .= '<td style="text-align:center;font-size:12px;" width="*">商品信息</td>';
    	$strTable .= '</tr>';
	    if(is_array($orderList)){
	    	$region	= get_region_list();
	    	foreach($orderList as $k=>$val){
	    		$strTable .= '<tr>';
	    		$strTable .= '<td style="text-align:center;font-size:12px;">&nbsp;'.$val['order_sn'].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$val['create_time'].' </td>';	    		
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$val['consignee'].'</td>';
                $strTable .= '<td style="text-align:left;font-size:12px;">'."{$region[$val['province']]},{$region[$val['city']]},{$region[$val['district']]},{$region[$val['twon']]}{$val['address']}".' </td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$val['mobile'].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$val['act_type'].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$val['goods_price'].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$val['order_amount'].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$val['rebate'].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$val['pay_name'].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$this->pay_status[$val['pay_status']].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$this->shipping_status[$val['shipping_status']].'</td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">`'.$this->getInvoiceNo($val['order_sn']).'</td>';
	    		$orderGoods = D('order_goods')->where('order_id='.$val['order_id'])->select();
	    		$strGoods="";
                $goods_num = 0;
	    		foreach($orderGoods as $goods){
                    $goods_num = $goods_num + $goods['goods_num'];
	    			$strGoods .= "商品编号：".$goods['goods_sn']." 商品名称：".$goods['goods_name'];
	    			if ($goods['spec_key_name'] != '') $strGoods .= " 规格：".$goods['spec_key_name'];
	    			$strGoods .= "<br />";
	    		}
	    		unset($orderGoods);
                $strTable .= '<td style="text-align:left;font-size:12px;">'.$goods_num.' </td>';
	    		$strTable .= '<td style="text-align:left;font-size:12px;">'.$strGoods.' </td>';
	    		$strTable .= '</tr>';
	    	}
	    }
    	$strTable .='</table>';
    	unset($orderList);
    	downloadExcel($strTable,'order');
    	exit();
    }

    /*
     * @Author : 赵磊
     * 根据订单好获取物流单号
     * */
    public function getInvoiceNo($order_sn)
    {
        $res = Db::table('tp_delivery_doc')
            ->field('invoice_no')
            ->where('order_sn',$order_sn)
            ->find();
        return $res['invoice_no'];
    }

    /**
     * 退货单列表
     */
    public function return_list(){
        return $this->fetch();
    }

    /*
     * ajax 退货订单列表
     */
    public function ajax_return_list(){
        // 搜索条件
        $order_sn =  trim(I('order_sn'));
        $order_by = I('order_by') ? I('order_by') : 'addtime';
        $sort_order = I('sort_order') ? I('sort_order') : 'desc';
        $status =  I('status');
        $where = [];
        if($order_sn){
            $where['order_sn'] =['like', '%'.$order_sn.'%'];
        }
        if($status != ''){
            $where['status'] = $status;
        }
        $count = M('return_goods')->where($where)->count();
        $Page  = new AjaxPage($count,13);
        $show = $Page->show();
        $list = M('return_goods')->where($where)->order("$order_by $sort_order")->limit("{$Page->firstRow},{$Page->listRows}")->select();
        $goods_id_arr = get_arr_column($list, 'goods_id');
        if(!empty($goods_id_arr)){
            $goods_list = M('goods')->where("goods_id in (".implode(',', $goods_id_arr).")")->getField('goods_id,goods_name');
        }
        $state = C('REFUND_STATUS');
        $return_type = C('RETURN_TYPE');
        $this->assign('state',$state);
        $this->assign('return_type',$return_type);
        $this->assign('goods_list',$goods_list);
        $this->assign('list',$list);
        $this->assign('pager',$Page);
        $this->assign('page',$show);// 赋值分页输出
        return $this->fetch();
    }

    /**
     * 添加订单
     */
    public function add_order()
    {
        //  获取省份
        $province = M('region')->where(array('parent_id'=>0,'level'=>1))->select();
        $this->assign('province',$province);
        return $this->fetch();
    }

    /**
     * 提交添加订单
     */
    public function addOrder(){
            $user_id = I('user_id');// 用户id 可以为空
            $admin_note = I('admin_note'); // 管理员备注
            //收货信息
            $user  = Db::name('users')->where(['user_id'=>$user_id])->find();
            $address['consignee'] = I('consignee');// 收货人
            $address['province'] = I('province'); // 省份
            $address['city'] = I('city'); // 城市
            $address['district'] = I('district'); // 县
            $address['address'] = I('address'); // 收货地址
            $address['mobile'] = I('mobile'); // 手机
            $address['zipcode'] = I('zipcode'); // 邮编
            $address['email'] = $user['email']; // 邮编
            $invoice_title = I('invoice_title');// 发票抬头
            $taxpayer = I('taxpayer');// 纳税人识别号

            $goods_id_arr = I("goods_id/a");
            $orderLogic = new OrderLogic();
            $order_goods = $orderLogic->get_spec_goods($goods_id_arr);
            $pay = new Pay();
            try{
                $pay->setUserId($user_id);
                $pay->payGoodsList($order_goods);
                $pay->delivery($address['district']);
                $pay->orderPromotion();
            } catch (TpshopException $t) {
                $error = $t->getErrorArr();
                $this->error($error['msg']);
            }
            $placeOrder = new PlaceOrder($pay);
            $placeOrder->setUserAddress($address);
            $placeOrder->setInvoiceTitle($invoice_title);
            $placeOrder->setTaxpayer($taxpayer);
            $placeOrder->addNormalOrder();
            $order = $placeOrder->getOrder();
            if($order) {
                M('order_action')->add([
                    'order_id'      => $order['order_id'],
                    'action_user'   => session('admin_id'),
                    'order_status'  => 0,  //待支付
                    'shipping_status' => 0, //待确认
                    'action_note'   => $admin_note,
                    'status_desc'   => '提交订单',
                    'log_time'      => time()
                ]);
                $this->success('添加商品成功',U("Admin/Order/detail",array('order_id'=>$order['order_id'])));
            } else{
                $this->error('添加失败');
            }
    }


    /**
     * 订单编辑
     * @return mixed
     */
    public function edit_order(){
        $order_id = I('order_id');
        $orderLogic = new OrderLogic();
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel->where(['order_id'=>$order_id])->find();
        $order =$orderObj->append(['full_address','orderGoods'])->toArray();
        if($order['shipping_status'] != 0){
            $this->error('已发货订单不允许编辑');
            exit;
        }
        $orderGoods = $order['orderGoods'];
        if(IS_POST)
        {
            $order['consignee'] = I('consignee');// 收货人
            $order['province'] = I('province'); // 省份
            $order['city'] = I('city'); // 城市
            $order['district'] = I('district'); // 县
            $order['address'] = I('address'); // 收货地址
            $order['mobile'] = I('mobile'); // 手机
            $order['invoice_title'] = I('invoice_title');// 发票
            $order['taxpayer'] = I('taxpayer');// 纳税人识别号
            $order['admin_note'] = I('admin_note'); // 管理员备注
            $order['admin_note'] = I('admin_note'); //
            $order['shipping_code'] = I('shipping');// 物流方式
            $order['shipping_name'] = Db::name('shipping')->where('shipping_code',I('shipping'))->getField('shipping_name');
            $order['pay_code'] = I('payment');// 支付方式
            $order['pay_name'] = M('plugin')->where(array('status'=>1,'type'=>'payment','code'=>I('payment')))->getField('name');
            $goods_id_arr = I("goods_id/a");
            $new_goods = $old_goods_arr = array();
            //################################订单添加商品
            if($goods_id_arr){
                $new_goods = $orderLogic->get_spec_goods($goods_id_arr);
                foreach($new_goods as $key => $val)
                {
                    $val['order_id'] = $order_id;
                    $rec_id = M('order_goods')->add($val);//订单添加商品
                    if(!$rec_id)
                        $this->error('添加失败');
                }
            }

            //################################订单修改删除商品
            $old_goods = I('old_goods/a');
            foreach ($orderGoods as $val){
                if(empty($old_goods[$val['rec_id']])){
                    M('order_goods')->where("rec_id=".$val['rec_id'])->delete();//删除商品
                }else{
                    //修改商品数量
                    if($old_goods[$val['rec_id']] != $val['goods_num']){
                        $val['goods_num'] = $old_goods[$val['rec_id']];
                        M('order_goods')->where("rec_id=".$val['rec_id'])->save(array('goods_num'=>$val['goods_num']));
                    }
                    $old_goods_arr[] = $val;
                }
            }

            $goodsArr = array_merge($old_goods_arr,$new_goods);
            $pay = new Pay();
            try{
                $pay->setUserId($order['user_id']);
                $pay->payOrder($goodsArr);
                $pay->delivery($order['district']);
                $pay->orderPromotion();
            } catch (TpshopException $t) {
                $error = $t->getErrorArr();
                $this->error($error['msg']);
            }
            //################################修改订单费用
            $order['goods_price']    = $pay->getGoodsPrice(); // 商品总价
            $order['shipping_price'] = $pay->getShippingPrice();//物流费
            $order['order_amount']   = $pay->getOrderAmount(); // 应付金额
            $order['total_amount']   = $pay->getTotalAmount(); // 订单总价
            $o = M('order')->where('order_id='.$order_id)->save($order);

            $l = $orderLogic->orderActionLog($order_id,'修改订单','修改订单');//操作日志
            if($o && $l){
                $this->success('修改成功',U('Admin/Order/editprice',array('order_id'=>$order_id)));
            }else{
                $this->success('修改失败',U('Admin/Order/detail',array('order_id'=>$order_id)));
            }
            exit;
        }
        // 获取省份
        $province = M('region')->where(array('parent_id'=>0,'level'=>1))->select();
        //获取订单城市
        $city =  M('region')->where(array('parent_id'=>$order['province'],'level'=>2))->select();
        //获取订单地区
        $area =  M('region')->where(array('parent_id'=>$order['city'],'level'=>3))->select();
        //获取支付方式
        $payment_list = M('plugin')->where(array('status'=>1,'type'=>'payment'))->select();
        //获取配送方式
        $shipping_list = Db::name('shipping')->field('shipping_name,shipping_code')->where('')->select();

        $this->assign('order',$order);
        $this->assign('province',$province);
        $this->assign('city',$city);
        $this->assign('area',$area);
        $this->assign('orderGoods',$orderGoods);
        $this->assign('shipping_list',$shipping_list);
        $this->assign('payment_list',$payment_list);
        return $this->fetch();
    }

    /**
     * 选择搜索商品
     */
    public function search_goods()
    {
    	$brandList =  M("brand")->select();
    	$categoryList =  M("goods_category")->select();
    	$this->assign('categoryList',$categoryList);
    	$this->assign('brandList',$brandList);
    	$where = 'exchange_integral = 0 and is_on_sale = 1 and is_virtual =' . I('is_virtual/d',0);//搜索条件
    	I('intro')  && $where = "$where and ".I('intro')." = 1";
    	if(I('cat_id')){
    		$this->assign('cat_id',I('cat_id'));    		
            $grandson_ids = getCatGrandson(I('cat_id')); 
            $where = " $where  and cat_id in(".  implode(',', $grandson_ids).") "; // 初始化搜索条件
                
    	}
        if(I('brand_id')){
            $this->assign('brand_id',I('brand_id'));
            $where = "$where and brand_id = ".I('brand_id');
        }
    	if(!empty($_REQUEST['keywords']))
    	{
    		$this->assign('keywords',I('keywords'));
    		$where = "$where and (goods_name like '%".I('keywords')."%' or keywords like '%".I('keywords')."%')" ;
    	}
        $goods_count =M('goods')->where($where)->count();
        $Page = new Page($goods_count,C('PAGESIZE'));
    	$goodsList = M('goods')->where($where)->order('goods_id DESC')->limit($Page->firstRow,$Page->listRows)->select();
                
        foreach($goodsList as $key => $val)
        {
            $spec_goods = M('spec_goods_price')->where("goods_id = {$val['goods_id']}")->select();
            $goodsList[$key]['spec_goods'] = $spec_goods;            
        }
        if($goodsList){
            //计算商品数量
            foreach ($goodsList as $value){
                if($value['spec_goods']){
                    $count += count($value['spec_goods']);
                }else{
                    $count++;
                }
            }
            $this->assign('totalSize',$count);
        }

    	$this->assign('page',$Page->show());
    	$this->assign('goodsList',$goodsList);
    	return $this->fetch();
    }
    
    public function ajaxOrderNotice(){
        $order_amount = M('order')->where("order_status=0 and (pay_status=1 or pay_code='cod')")->count();
        echo $order_amount;
    }

    /**
     * 删除订单日志
     */
    public function delOrderLogo(){
        $ids = I('ids');
        empty($ids) &&  $this->ajaxReturn(['status' => -1,'msg' =>"非法操作！",'url'  =>'']);
        $order_ids = rtrim($ids,",");
        $res = Db::name('order_action')->whereIn('order_id',$order_ids)->delete();
        if($res !== false){
            $this->ajaxReturn(['status' => 1,'msg' =>"删除成功！",'url'  =>'']);
        }else{
            $this->ajaxReturn(['status' => -1,'msg' =>"删除失败",'url'  =>'']);
        }
    }


    /*
* @Author : 赵磊
* 卡券订单列表
* */
    public function virtualOrderList()
    {
        //筛选条件
        $data = I('post.');
        $data['typeTab'] = I('typeTab');//订单状态类型;默认全部;1未消费;2待评价;3已完成;4待付款;5已退款;6已取消
        if($data['startTime']=='') $data['startTime'] = date('Y-m-d',time()-30*24*3600);  //开始时间
        if($data['endTime']=='') $data['endTime'] = date('Y-m-d',time());  //结束时间
        //数据导出
        if ($data['export'] == 1){
            (new \app\admin\model\Order())->export($data);
        }
        if (IS_AJAX){
            $orderList = (new \app\admin\model\Order())->getVrOrderInfoList($data);//获取全部数据
            if ($data['order_by'] != 'be_partner_start'){
                $orderList = (new \app\admin\model\Order())->VrOrderInfoListSort($data,$orderList);//排序
            }
            //分页操作
            $count = count($orderList);//获取所有虚拟订单数量
            $Page  = new AjaxPage($count,12);
            $show = $Page->show();
            $orderList = array_slice($orderList,$Page->firstRow,12);
            $this->assign('page',$show);
            $this->assign('count',$count);//订单总量
            $this->assign('orderList',$orderList);//列表信息
            return $this->fetch('virtualOrderList_ajax');
        }
        $this->assign('search',$data);//筛选条件
        return $this->fetch();
    }

    /*
    * @Author : 赵磊
    * 卡券订单详情
     * */
    public function virtualOrderInfo(\app\admin\model\Order $orderModel)
    {
        $orderId = I('order_id');
        $result = $orderModel->getVrOrderInfo($orderId);
//        halt($result);
        $this->assign('orderInfo',$result['orderInfo']);//订单信息,用户信息,商品信息
        $this->assign('vrCode',$result['vrCode']);//缤纷券密码
        $this->assign('consumption',$result['consumption']);//订单卡券消费状态
        $this->assign('count',count($result['vrCode']));//缤纷券密码数量
        $this->assign('testUserInfo',$result['testUserInfo']);//体检人信息
        $this->assign('log',$result['log']);//操作记录
        return $this->fetch();
    }

    /*
     * @Author : 赵磊
     * 卡券密码申请退款
     * */
    public function applyRefundVrCode()
    {
        $rec_id = I('rec_id');
        //更改卡券密码状态为锁定,到卡券退款订单
        $res = Db::table('tp_vr_order_code')->where('rec_id',$rec_id)->update(['refund_lock'=>1]);
        if ($res){
            return json(['data'=>1,'msg'=>'申请退款成功,请到卡券退款单处理']);
        }else{
            return json(['data'=>-1,'msg'=>'申请退款失败']);
        }
    }

    /*
     * @Author : 赵磊
     * 卡券密码退款,订单状态改变
     * */
    public function refundVrCodeStateChange($orderId)
    {
//        $rec_id = I('rec_id');
//        //判断是否所有密码状态是否全部退款成功
//        $orderVr = Db::table('tp_vr_order_code')->field('order_id')->where('rec_id',$rec_id)->find();
//        $orderId = $orderVr['order_id'];
        $usedVr = Db::table('tp_vr_order_code')->where(['order_id'=>$orderId,'refund_lock'=>3])->count();//退款成功的
        $vrCodeNum = Db::table('tp_vr_order_code')->where(['order_id'=>$orderId])->count();//该订单所有的
        //如果该订单所有密码卡券全部退款成功,改变该笔订单状态为已退款
        if ($usedVr == $vrCodeNum){
            $res = Db::table('tp_order')->where('order_id',$orderId)->update(['pay_status'=>3]);
        }
        return $res;

    }


    /*
     * @Author : 赵磊
     * 卡券退款单列表
     * */
    public function refundVrcodeList()
    {
        $data = I('post.');
        $order_by = I('order_by','add_time');//排序字段
        if (I('sort','desc') == 'desc'){//顺序
            $sort = 'SORT_DESC';
        }else{
            $sort = 'SORT_ASC';
        }
        $order = new \app\admin\model\Order();
        $condition['refund_lock'] = ['<>',0];//锁定状态
        if ($data['refund_lock'] !='') $condition['refund_lock'] = $data['refund_lock'];//状态搜索
        $searchVal = $data['keywords'];
        //搜索得到相关userId
        if ($searchVal != ''){
            $where1['order_sn'] = ['like',"%$searchVal%"];
            $orderUser = Db::table('tp_order')->field('user_id')->where($where1)->select();
            $user = array_column($orderUser,'user_id');
            if (empty($orderUser)) {
                $where2['nickname|mobile'] = ['like',"%$searchVal%"];
                $users = Db::table('tp_users')->field('user_id')->where($where2)->select();
                $user = array_column($users,'user_id');
            }
            $condition['user_id'] = array('in',$user);
        }
        //时间搜索
        if ($data['endTime'] !=''){
            $start = strtotime($data['startTime']);
            $end = strtotime($data['endTime'])+24*3600;
            $orderId = Db::table('tp_order')
                ->field('order_id')
                ->where('add_time','between time',[$start,$end])
                ->select();
            if (!empty($orderId)) $orderId = array_column($orderId,'order_id');
            $condition['order_id'] = array('in',$orderId);
        }
//        $count = (new VrOrderCode())->distinct(true)->where($condition)->count();//退款单数量
//        $page = new AjaxPage($count,20);
//        $show = $page->show();

        $vrCode = Db::table('tp_vr_order_code')
            ->distinct(true)
            ->where($condition)
//            ->limit($page->firstRow,$page->listRows)
            ->select();
        for ($i=0;$i<count($vrCode);$i++){
            $vrCode[$i]['orderInfo'] = $order->getVrOrderInfo($vrCode[$i]['order_id'])['orderInfo'];
            $vrCode[$i]['order_sn'] = $vrCode[$i]['orderInfo']['order_sn'];
            $vrCode[$i]['add_time'] = $vrCode[$i]['orderInfo']['add_time'];
            $vrCode[$i]['pay_time'] = $vrCode[$i]['orderInfo']['pay_time'];
        }
//        halt($vrCode);
        $order_by = array_column($vrCode,'order_sn');//根据哪个字段排
//        halt($order_by);
        if ($data['sort'] == 'asc')array_multisort($order_by,SORT_ASC,$vrCode);
        if ($data['sort'] == 'desc')array_multisort($order_by,SORT_DESC,$vrCode);//数组排序
        $count = count($vrCode);
        $page = new AjaxPage($count,20);
        $show = $page->show();
        if ($count !=0) $vrCode = array_slice($vrCode,$page->firstRow,20);
        $this->assign('page',$show);//赋值分页输出
        $this->assign('count',$count);//数据数量
        $this->assign('vrCode',$vrCode);//卡券退款单数据
        if (IS_AJAX){
            return $this->fetch('refundVrcodeList_ajax');
        }
        return $this->fetch();
    }


    /*
     * @Author: 赵磊
     *退款订单详情
     * **/
    public function refundVrcodeInfo()
    {
        $recId = I('rec_id');
        $order = new \app\admin\model\Order();
        $orderId = Db::table('tp_vr_order_code')->field('order_id')->where('rec_id',$recId)->find();
        $Info = $order->getVrOrderInfo($orderId['order_id']);//该卡券所在订单的订单详情
        $vrCode = Db::table('tp_vr_order_code')->where('rec_id',$recId)->find();
        $this->assign('rec_id',$recId);//卡券密码id
        $this->assign('vrCode',$vrCode);//卡券shuju
        $this->assign('orderId',$orderId['order_id']);//卡券所在订单id
        $this->assign('orderInfo',$Info['orderInfo']);//订单详情
//        halt($vrCode);
        return $this->fetch();
    }


    /*
     * @Author : 赵磊
     * 卡券密码退款
     * */
    public function refundVrOrder(){
        $data = I('post.');
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::get(['order_id'=>$data['order_id']]);
        $order =$orderObj->append(['full_address'])->toArray();
        $order['rec_id'] = $data['rec_id'];//卡券兑换码id
        $order['refundRemark'] = $data['remark'];
        $where_refund['order_id'] = $order['order_id'];
        $where_refund['refund_lock'] = 3;//退款成功
        $refundNum = (new VrOrderCode())->where($where_refund)->count();//退款成功次数
        $refundAmount = $order['order_amount'] - $refundNum * $order['goods_price'];//剩余支付宝应退款金额
        if(!$order){
            $this->error('订单不存在或参数错误');
        }

        //退款成功发送短信提醒,并取消分成
        if ($data['pay_status'] == 3){
            $func = function ($user_id) use ($orderObj) {
                if (!config('APP_DEBUG')) {
                    $nicknameMobile = (new Users())->where('user_id', $user_id)->getField('nickname,mobile');
                    if ($nicknameMobile) {
                        $nickname = array_keys($nicknameMobile)[0];
                        $mobile = array_values($nicknameMobile)[0];
                        $resp = sendSms('2', $mobile, ['name' => $nickname]);
                        if ($resp['status'] != 1) {
                            Logs::sentryLogs('发送短信失败');
                        }
                    }
                }

                //减去该兑换码所属订单分成
                try{
                    (new DistributeDivideLog())->VrCodeCancelSplit($orderObj->order_sn);
                }catch(Exception $exception){
                    Logs::sentryLogs($exception,['msg' => '取消分成失败，订单号：'.$orderObj->order_sn]);
                }
            };
        }



        if($data['pay_status'] == 3){
            $refundLogic = new RefundLogic();
            if($data['refund_type'] == 1){
                //取消订单退款退余额
                if($refundLogic->updateVrCodeRefundOrder($order,2)){
                    $func($order['user_id']);
                    $this->refundVrCodeStateChange($data['order_id']);
                    return json(['data'=>1,'msg'=>'成功退款到账户余额']);
                }else{
                    return json(['data'=>-2,'msg'=>'退款失败']);
                }
            }
            if($data['refund_type']== 0){
                $time = time();
                //取消订单支付原路退回
                if($order['pay_code'] == 'weixin' || $order['pay_code'] == 'alipay' || $order['pay_code'] == 'alipayMobile'){
                    /*code_4支付原路退回逻辑*/
                    $return_money = $order['goods_price'];//单个密码价格
                    if($order['pay_code'] == 'weixin'){
                        include_once  PLUGIN_PATH."payment/weixin/weixin.class.php";
                        $payment_obj =  new \weixin();

                        if ($order['user_money'] > 0){//混合支付;先退支付再退余额
                            if ($refundAmount <= 0) {
                                return json(['data'=>-2,'msg'=>'支付原路退款失败,请使用余额退款或联系开发人员']);
                            }
                            if ($refundAmount >= $order['goods_price']){
                                $refundAmount = $order['goods_price'];
                            }
                            $order['remainingRefund'] = $refundAmount;//剩余在线支付应退款金额
                            $datas = array('transaction_id'=>$order['transaction_id'],'total_fee'=>$order['order_amount'],'refund_fee'=>$refundAmount,'out_refund_no'=>time());
                        }else{
                            $datas = array('transaction_id'=>$order['transaction_id'],'total_fee'=>$order['order_amount'],'refund_fee'=>$return_money,'out_refund_no'=>time());
                        }

                        $result = $payment_obj->payment_refund($datas);
                        if ($result['return_code'] == 'SUCCESS'  && $result['result_code'] == 'SUCCESS') {
                            if($refundLogic->updateVrCodeRefundOrder($order)){
                                $func($order['user_id']);
                                $this->refundVrCodeStateChange($data['order_id']);//判断是否所有密码都退款
                                return json(['data'=>1,'msg'=>'支付原路退款成功']);
                            }else{
                                $func($order['user_id']);
                                return json(['data'=>-2,'msg'=>'支付原路退款成功,余额支付部分退款失败']);
                            }
                        }else{
                            return json(['data'=>-2,'msg'=>'支付原路退款失败']);
                        }
                    }else{
                        //修改该退款方式，使用支付宝应用退款
                        $alipayConfig = Config::get('alipay');
                        if ($order['user_money'] > 0){
                            if ($refundAmount <= 0){
                                return json(['data'=>-2,'msg'=>'支付原路退款失败,请使用余额退款或联系开发人员']);
                            }
                            if ($refundAmount >= $order['goods_price']){
                                $refundAmount = $order['goods_price'];
                            }
                            $order['remainingRefund'] = $refundAmount;//剩余在线支付应退款金额
                            $refunDataForAlipay = [
                                'out_trade_no' => $order['order_sn'],
                                'out_request_no' => $time,
                                'refund_amount' => (string)$refundAmount
                            ];
                        }else{
                            $refunDataForAlipay = [
                                'out_trade_no' => $order['order_sn'],
                                'out_request_no' => $time,
                                'refund_amount' => $order['goods_price']
                            ];
                        }

                        $refundResult = \Yansongda\Pay\Pay::alipay($alipayConfig)->refund($refunDataForAlipay);

                        if ($refundResult->code == 10000 && $refundResult->fund_change == 'Y') {
                            if($refundLogic->updateVrCodeRefundOrder($order)){
                                $func($order['user_id']);
                                $this->refundVrCodeStateChange($data['order_id']);//判断是否所有密码都退款
                                return json(['data'=>1,'msg'=>'支付原路退款成功']);
                            }else{
                                $func($order['user_id']);
                                return json(['data'=>-2,'msg'=>'支付原路退款失败,余额支付部分退款失败']);
                            }
                        }
                        return json(['data'=>-2,'msg'=>'支付原路退款失败,请联系开发人员']);
                    }
                    /*code_4支付原路退回逻辑*/
                }else{
                    return json(['data'=>-2,'msg'=>'该订单支付方式不支持在线退回']);
                }
            }
        }else{
            M('vr_order_code')->where(array('rec_id'=>$order['rec_id']))->save(['refund_lock'=>4,'remark'=>$data['remark']]);
            return json(['data'=>1,'msg'=>'拒绝退款操作成功']);
        }
    }

    /*
     * @Author : 赵磊
     * 根据订单获取订单返利信息
     * */
    public function getOrderRebate($order_sn)
    {
        $user = new UserModel();
        $res = Db::table('cf_distribute_divide_log')->where('order_sn',$order_sn)->select();//返利信息
        $sum=[];
        for ($i=0;$i<count($res);$i++){
            $res[$i]['userInfo'] = $user->getUserRelationIdentity($res[$i]['to_user_id']);//被返利用户信息
            $sum[] = $res[$i]['divide_money'];
        }
        $sum = array_sum($sum);//返利合计
        return ['sum'=>$sum,'rebate'=>$res];
    }
}
