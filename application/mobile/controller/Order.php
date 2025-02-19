<?php
/**
 * tpshop
 * ============================================================================
 * * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和使用 .
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 采用最新Thinkphp5助手函数特性实现单字母函数M D U等简写方式
 * ============================================================================
 * 2015-11-21
 */
namespace app\mobile\controller;

use app\common\library\Logs;
use app\common\logic\ActivityLogic;
use app\common\logic\OssLogic;
use app\common\logic\WechatLogic;
use app\common\model\TeamFound;
use app\common\logic\UsersLogic;
use app\common\logic\OrderLogic;
use app\common\logic\CommentLogic;
use app\common\model\Users;
use app\mobile\model\BargainActivityModel;
use app\mobile\model\BargainFoundModel;
use think\Cache;
use think\Cookie;
use think\Page;
use think\Request;
use think\db;

class Order extends MobileBase
{

    public $user_id = 0;
    public $user = array();

    public function _initialize()
    {
        parent::_initialize();

        $this->checkUserLogin();
    }

    /**
     * 订单列表
     * @return mixed
     */
    public function order_list()
    {
        $where = ' user_id=' . $this->user_id;
        //条件搜索
        $type = I('get.type');
        if($type){
            $where .= C(strtoupper($type));
//            if ($type== 'WAITSEND' || $type =='WAITRECEIVE' || $type =='PORTIONSEND') { //待发货不显示自提单
//                $where .= " and shipping_code <> 'ZITI' ";
//            }
        }
        $where.=' and prom_type not in (5,6) ';//虚拟订单和拼团订单不列出来
        $count = M('order')->where($where)->count();
        $Page = new Page($count, 10);
        $show = $Page->show();
        $order_str = "order_id DESC";
        $order_list = M('order')->order($order_str)->where($where)->limit($Page->firstRow . ',' . $Page->listRows)->select();
        //获取订单商品
        $model = new UsersLogic();
        foreach ($order_list as $k => $v) {
            $order_list[$k] = set_btn_order_status($v);  // 添加属性  包括按钮显示属性 和 订单状态显示属性
//            $order_list[$k]['total_fee'] = $v['goods_amount'] + $v['shipping_price'] - $v['integral_money'] -$v['user_money'] - $v['coupon_price'] - $v['discount']; //订单总额
            $data = $model->get_order_goods($v['order_id']);
            $order_list[$k]['goods_list'] = $data['result'];
        }

        //统计订单商品数量
        foreach ($order_list as $key => $value) {
            $count_goods_num = 0;
            foreach ($value['goods_list'] as $kk => $vv) {
                $count_goods_num += $vv['goods_num'];
            }
            $order_list[$key]['count_goods_num'] = $count_goods_num;
            //新增prom_type=7 砍价商品订单的砍价商品类型 actType = 0 免费拿;1底价购
            if ($order_list[$key]['prom_type'] == 7){
                $actType = (new BargainActivityModel())
                    ->where('id',$order_list[$key]['prom_id'])
                    ->getField('act_type');
                $order_list[$key]['act_type'] = $actType;//砍价商品类型 actType = 0 免费拿;1底价购
            }
        }

        foreach ($order_list as $k=>$v){
           if($v['shipping_code']=='ZITI') $order_list[$k]['order_pick_up_code'] = (new OrdersPickUp())->getPickUpCode($v['order_id']);//自提订单自提码信息
        }
        $this->assign('order_status', C('ORDER_STATUS'));
        $this->assign('shipping_status', C('SHIPPING_STATUS'));
        $this->assign('pay_status', C('PAY_STATUS'));
        $this->assign('page', $show);
        $this->assign('lists', $order_list);
        $this->assign('active', 'order_list');
        $this->assign('active_status', I('get.type'));
        if ($_GET['is_ajax']) {
            return $this->fetch('ajax_order_list');
            exit;
        }
        return $this->fetch();
    }
    //拼团订单列表
    public function team_list(){
        $type = input('type');
        $Order = new \app\common\model\Order();
        $order_where = ['prom_type' => 6, 'user_id' => $this->user_id, 'deleted' => 0,'pay_code'=>['<>','cod']];//拼团基础查询
        $listRows = 10;
        switch (strval($type)) {
            case 'WAITPAY':
                //待支付订单
                $order_where['pay_status'] = 0;
                $order_where['order_status'] = 0;
                break;
            case 'WAITTEAM':
                //待成团订单
                $found_order_id = Db::name('team_found')->where(['user_id'=>$this->user_id,'status'=>1])->limit($listRows)->order('found_id desc')->getField('order_id',true);//团长待成团
                $follow_order_id = Db::name('team_follow')->where(['follow_user_id'=>$this->user_id,'status'=>1])->limit($listRows)->order('follow_id desc')->getField('order_id',true);//团员待成团
                $team_order_id = array_merge($found_order_id,$follow_order_id);
                if (count($team_order_id) > 0) {
                    $order_where['order_id'] = ['in', $team_order_id];
                }else{
                    $order_where['order_id'] = 0;
                }
                break;
            case 'WAITSEND':
                //待发货
                $order_where['pay_status'] = 1;
                $order_where['shipping_status'] = ['<>',1];
                $order_where['order_status'] = ['elt',1];
                $found_order_id = Db::name('team_found')->where(['user_id'=>$this->user_id,'status'=>2])->limit($listRows)->order('found_id desc')->getField('order_id',true);//团长待成团
                $follow_order_id = Db::name('team_follow')->where(['follow_user_id'=>$this->user_id,'status'=>2])->limit($listRows)->order('follow_id desc')->getField('order_id',true);//团员待成团
                $team_order_id = array_merge($found_order_id,$follow_order_id);
                if (count($team_order_id) > 0) {
                    $order_where['order_id'] = ['in', $team_order_id];
                }else{
                    $order_where['order_id'] = 0;
                }
                break;
            case 'WAITRECEIVE':
                //待收货
                $order_where['shipping_status'] = 1;
                $order_where['order_status'] = 1;
                break;
            case 'WAITCCOMMENT':
                //已完成
                $order_where['order_status'] = 2;
                break;
        }
        $request = Request::instance();
        $order_count = $Order->where($order_where)->count();
        $page = new Page($order_count, $listRows);
        $order_list = $Order->with('orderGoods')->where($order_where)->limit($page->firstRow . ',' . $page->listRows)->order('order_id desc')->select();
        $this->assign('order_list',$order_list);
        if ($request->isAjax()) {
            return $this->fetch('ajax_team_list');
        }
        return $this->fetch();
    }

    public function team_detail(){
        $order_id = input('order_id');
        $Order = new \app\common\model\Order();
        $TeamFound = new TeamFound();
        $order_where = ['prom_type' => 6, 'order_id' => $order_id, 'deleted' => 0];
        $order = $Order->with('orderGoods')->where($order_where)->find();
        if (empty($order)) {
            $this->error('该订单记录不存在或已被删除');
        }
        $orderTeamFound = $order->teamFound;
        if ($orderTeamFound) {
            //团长的单
            $this->assign('orderTeamFound', $orderTeamFound);//团长
        } else {
            //去找团长
            $teamFound = $TeamFound::get(['found_id' => $order->teamFollow['found_id']]);
            $this->assign('orderTeamFound', $teamFound);//团长
        }
        $this->assign('order', $order);
        return $this->fetch();
    }

    /**
     * 订单详情
     * @return mixed
     */
    public function order_detail()
    {
        $id = I('get.id/d');
        $map['order_id'] = $id;
        $map['user_id'] = $this->user_id;
        $order_info = M('order')->where($map)->find();
        //自提订单自提码
        $order_info['order_pick_up_code'] = (new OrdersPickUp())->getPickUpCode($id);

        $order_info = set_btn_order_status($order_info);  // 添加属性  包括按钮显示属性 和 订单状态显示属性
        if (!$order_info) {
            $this->error('没有获取到订单信息');
            exit;
        }
        //虚拟订单跳转
        if ($order_info['prom_type'] == 5) {
            header('Location:'.U('Mobile/Virtual/orderResult',[ 'order_id' => $id]));
        }

        //拼团订单跳转
        if ($order_info['prom_type'] == 6) {
            $foundId = Db::name('team_found')->where('order_id',$id)->getField('found_id');
            if (empty($foundId)) {
                $foundId = Db::name('team_follow')->where('order_id',$id)->getField('found_id');
            }
            header('Location:'.U('Mobile/Team/myInfo',[ 'found_id' => $foundId]));
        }
        //获取订单商品
        $model = new UsersLogic();
        $data = $model->get_order_goods($order_info['order_id']);
        $order_info['goods_list'] = $data['result'];
        if($order_info['prom_type'] == 4){
            $pre_sell_item =  M('goods_activity')->where(array('act_id'=>$order_info['prom_id']))->find();
            $pre_sell_item = array_merge($pre_sell_item,unserialize($pre_sell_item['ext_info']));
            $order_info['pre_sell_is_finished'] = $pre_sell_item['is_finished'];
            $order_info['pre_sell_retainage_start'] = $pre_sell_item['retainage_start'];
            $order_info['pre_sell_retainage_end'] = $pre_sell_item['retainage_end'];
            $order_info['pre_sell_deliver_goods'] = $pre_sell_item['deliver_goods'];
        }else{
            $order_info['pre_sell_is_finished'] = -1;//没有参与预售的订单
        }
        $region_list = get_region_list();
        $invoice_no = M('DeliveryDoc')->where("order_id", $id)->getField('invoice_no', true);
        $order_info['invoice_no'] = implode(' , ', $invoice_no);

        $delivery = M('delivery_doc')->where("order_id", $id)->find();

        $deliveryMsg = queryExpress('',$delivery['invoice_no']);

        $deliveryStatus['is_check'] = 0;
        $deliveryStatus['msg'] = $deliveryMsg['data'][0];

        if ( empty($deliveryStatus['msg']) ) {
            $deliveryStatus['msg'] = '订单信息有误';
            $deliveryStatus['is_check'] = 0;
        }

        if(empty($delivery) || empty($delivery['invoice_no'])){
            $deliveryStatus['msg'] = '未发货';
            $deliveryStatus['is_check'] = 0;
        }

        if ($order_info['order_status_code'] == 'WAITCCOMMENT' || $order_info['order_status_code'] == 'FINISH') {
            $deliveryStatus['is_check'] = 1;
        }

        //待支付
        if ($order_info['order_status_code'] == 'WAITPAY' ) {
            $deliveryStatus['msg'] = null;
        }

        //已取消
        if ($order_info['order_status_code'] == 'CANCEL' ) {
            $deliveryStatus['msg'] = null;
            $deliveryStatus['is_check'] = 1;
        }
        //订单是砍价商品订单
        if($order_info['prom_type'] == 7){
            $act = (new BargainFoundModel())->field('goods_price,price')->where('order_id',$order_info['order_id'])->find();
            $order_info['act_price'] = $act['goods_price']-$act['price'];//砍去多少钱
        };
        $this->assign('delivery_status',$deliveryStatus);
        //获取订单操作记录
        $order_action = M('order_action')->where(array('order_id' => $id))->select();
        $this->assign('order_status', C('ORDER_STATUS'));
        $this->assign('shipping_status', C('SHIPPING_STATUS'));
        $this->assign('pay_status', C('PAY_STATUS'));
        $this->assign('region_list', $region_list);
        $this->assign('order_info', $order_info);
        $this->assign('order_action', $order_action);

        $func = function ($userInfo) {
            if (!$userInfo) {
                return [];
            }
            $userSex = '保密';
            if ($userInfo['sex'] == 1) {
                $userSex = '男';
            }elseif ($userInfo['sex'] == 2) {
                $userSex = '女';
            }
            $address = get_user_address_list($userInfo['user_id']);


            if ($address) {
                $addressInfo = getTotalAddress($address[0]['province'],$address[0]['city'],$address[0]['district'],$address[0]['twon'],$address[0]['address']);
            }else {
                $addressInfo = '未填写';
            }
            $returnData = [
                'name' => $userInfo['nickname'],
                'address' => $addressInfo,
                'age' => $userInfo['age'] ? $userInfo['age'] : '未填写',
                'email' => $userInfo['email'],
                'gender' => $userSex,
                'tel' => $userInfo['mobile'],
            ];
            return $returnData;
        };

        $userInfo = session('user');
        $this->assign('service_user_info',$func($userInfo));
        if (I('waitreceive')) {  //待收货详情
            return $this->fetch('wait_receive_detail');
        }
        return $this->fetch();
    }

    /**
     * 物流信息
     * @return mixed
     */
    public function express()
    {
        $order_id = I('get.order_id/d', 0);
        $order_goods = M('order_goods')->where("order_id", $order_id)->select();
        if(empty($order_goods) || empty($order_id)){
            $this->error('没有获取到订单信息');
        }
        $delivery = M('delivery_doc')->where("order_id", $order_id)->find();

        if ( empty($delivery)) {
            $delivery = M('order')->where("order_id", $order_id)->find();
            if( empty($delivery['shipping_code'])){
                $this->error('运单号有误');
            }
        }

        $this->assign('order_goods', $order_goods);
        $this->assign('delivery', $delivery);
        return $this->fetch();
    }

    /**
    * 取消订单
    */
    public function cancel_order()
    {
        $id = I('get.id/d');
        //检查是否有积分，余额支付
        $logic = new OrderLogic();
        $data = $logic->cancel_order($this->user_id, $id);
        if ($data['status'] == 1) {
            $orderInfo = (new \app\common\model\Order())->alias('a')
                ->join(['tp_order_goods' => 'b'],'a.order_id = b.order_id','LEFT')
                ->where('a.order_id',$id)->select()->toArray();
            //发送消息
            $res = (new WechatLogic())->sendTemplateMsgOnCancleOrder($orderInfo);

            if ($res['status'] != 1) {
                Logs::sentryLogs('发送取消订单模板消息失败:'.$res['msg']);
            }
        }

        $this->ajaxReturn($data);
    }
    /**
     * 确定收货成功
     */
    public function order_confirm()
    {
        $id = I('id/d', 0);
        $data = confirm_order($id, $this->user_id);
        if(request()->isAjax()){
            $this->ajaxReturn($data);
        }
        if ($data['status'] != 1) {
            $this->error($data['msg'],U('Mobile/Order/order_list'));
        } else {
            $model = new UsersLogic();
            $order_goods = $model->get_order_goods($id);
            $this->assign('order_goods', $order_goods);
            //是否首单,首单给上级发券
            (new ActivityLogic())->firstOrderCoupon($this->user_id);
            //是否首单,首单给上级发券-end-
            return $this->fetch();
            exit;
        }
    }
    //订单支付后取消订单
    public function refund_order()
    {
        $order_id = I('get.order_id/d');

        $order = M('order')
            ->field('order_id,pay_code,pay_name,user_money,integral_money,coupon_price,order_amount,consignee,mobile')
            ->where(['order_id' => $order_id, 'user_id' => $this->user_id])
            ->find();

        $this->assign('user',  $this->user);
        $this->assign('order', $order);
        return $this->fetch();
    }
    //申请取消订单
    public function record_refund_order()
    {
        $order_id   = input('post.order_id', 0);
        $user_note  = input('post.user_note', '');
        $consignee  = input('post.consignee', '');
        $mobile     = input('post.mobile', '');

        $logic = new \app\common\logic\OrderLogic;
        $return = $logic->recordRefundOrder($this->user_id, $order_id, $user_note, $consignee, $mobile);
        if ($return['status'] == 1) {
            $orderInfo = (new \app\common\model\Order())->alias('a')
                ->join(['tp_order_goods' => 'b'],'a.order_id = b.order_id','LEFT')
                ->where('a.order_id',$order_id)->select()->toArray();
            //发送消息
            $res = (new WechatLogic())->sendTemplateMsgOnCancleOrder($orderInfo);

            if ($res['status'] != 1) {
                Logs::sentryLogs('发送申请取消订单模板消息失败');
            }
        }

        $this->ajaxReturn($return);
    }

    /**
     * 申请退货
     */
    public function return_goods()
    {
        $rec_id = I('rec_id',0);
        $return_goods = M('return_goods')->where(array('rec_id'=>$rec_id))->find();
        if(!empty($return_goods))
        {
            $this->redirect(U('Order/return_goods_info',array('id'=>$return_goods['id'])));
        }
        $order_goods = M('order_goods')->alias('a')->field('a.*,b.original_img')
            ->where(array('rec_id'=>$rec_id))
            ->join(['tp_goods' => 'b'],'a.goods_id = b.goods_id','LEFT')->find();
        $order = M('order')->where(array('order_id'=>$order_goods['order_id'],'user_id'=>$this->user_id))->find();
        $confirm_time_config = tpCache('shopping.auto_service_date');//后台设置多少天内可申请售后
        $confirm_time = $confirm_time_config * 24 * 60 * 60;
        if ((time() - $order['confirm_time']) > $confirm_time && !empty($order['confirm_time'])) {
            $this->error('已经超过' . $confirm_time_config . "天内退货时间");
        }
        if(empty($order))$this->error('非法操作');
        if(IS_POST)
        {
            $model = new OrderLogic();
            $res = $model->addReturnGoods($rec_id,$order);  //申请售后
            if($res['status']==1){
                //发送模板消息
                $orderInfo = (new \app\common\model\Order())->alias('a')
                    ->join(['tp_order_goods' => 'b'],'a.order_id = b.order_id','LEFT')
                    ->where('a.order_id',$order_goods['order_id'])->select()->toArray();

                $result = (new WechatLogic())->sendTemplateMsgOnReturnOrderSumbit($orderInfo,$_POST);
                if ($result['status'] != 1) {
                    Logs::sentryLogs('发送申请退货模板消息失败');
                }
                $this->success($res['msg'],U('Order/return_goods_list'));
            }
            $this->error($res['msg']);
        }
        $region_id[] = tpCache('shop_info.province');
        $region_id[] = tpCache('shop_info.city');
        $region_id[] = tpCache('shop_info.district');
        $region_id[] = 0;
        $return_address = M('region')->where("id in (".implode(',', $region_id).")")->getField('id,name');
        $this->assign('return_address', $return_address);
        $this->assign('return_type', C('RETURN_TYPE'));
        $this->assign('goods', $order_goods);
        $this->assign('order',$order);
        return $this->fetch();
    }

    /**
     * 退换货列表
     */
    public function return_goods_list()
    {
        //退换货商品信息
        $count = M('return_goods')->where("user_id", $this->user_id)->count();
        $pagesize = C('PAGESIZE');
        $page = new Page($count, $pagesize);
        $list = Db::name('return_goods')->alias('rg')
            ->field('rg.*,og.goods_name,og.spec_key_name,a.original_img')
            ->join('order_goods og','rg.rec_id=og.rec_id','LEFT')
            ->join('goods a','rg.goods_id=a.goods_id','LEFT')
            ->where(['rg.user_id'=>$this->user_id])
            ->order("rg.id desc")
            ->limit("{$page->firstRow},{$page->listRows}")
            ->select();
        $state = C('REFUND_STATUS');
        $this->assign('list', $list);
        $this->assign('state',$state);
        $this->assign('page', $page->show());// 赋值分页输出
        if (I('is_ajax')) {
            return $this->fetch('ajax_return_goods_list');
            exit;
        }
        return $this->fetch();
    }

    /**
     *  退货详情
     */
    public function return_goods_info()
    {
        $id = I('id/d', 0);
        $return_goods = M('return_goods')->where("id = $id")->find();
        if(empty($return_goods)){
            $this->error('参数错误');
        }
        $return_goods['seller_delivery'] = unserialize($return_goods['seller_delivery']);  //订单的物流信息，服务类型为换货会显示
        $return_goods['delivery'] = unserialize($return_goods['delivery']);  //订单的物流信息，服务类型为换货会显示
        if ($return_goods['imgs'])
            $return_goods['imgs'] = explode(',', $return_goods['imgs']);
        $goods = M('order_goods')->where("rec_id = {$return_goods['rec_id']} ")->find();
        $this->assign('state',C('REFUND_STATUS'));
        $this->assign('return_type', C('RETURN_TYPE'));
        $this->assign('goods', $goods);
        $this->assign('return_goods', $return_goods);

        return $this->fetch();
    }

    /**
     * 修改退货状态，发货
     */
    public function checkReturnInfo()
    {
        $data = I('post.');
        $data['delivery'] = serialize($data['delivery']);
        $data['status'] = 2;
        $res = M('return_goods')->where(['id'=>$data['id'],'user_id'=>$this->user_id])->save($data);
        if($res !== false){
            $this->ajaxReturn(['status'=>1,'msg'=>'发货提交成功','url'=>'']);
        }else{
            $this->ajaxReturn(['status'=>-1,'msg'=>'提交失败','url'=>'']);
        }
    }

    public function return_goods_refund()
    {
        $order_sn = I('order_sn');
        $where = array('user_id'=>$this->user_id);
        if($order_sn){
            $where['order_sn'] = $order_sn;
        }
        $where['status'] = 5;
        $count = M('return_goods')->where($where)->count();
        $page = new Page($count,10);
        $list = M('return_goods')->where($where)->order("id desc")->limit($page->firstRow, $page->listRows)->select();
        $goods_id_arr = get_arr_column($list, 'goods_id');
        if(!empty($goods_id_arr))
            $goodsList = M('goods')->where("goods_id in (".  implode(',',$goods_id_arr).")")->getField('goods_id,goods_name');
        $this->assign('goodsList', $goodsList);
        $state = C('REFUND_STATUS');
        $this->assign('list', $list);
        $this->assign('state',$state);
        $this->assign('page', $page->show());// 赋值分页输出
        return $this->fetch();
    }

    /**
     * 取消售后服务
     * @author lxl
     * @time 2017-4-19
     */
    public function return_goods_cancel(){
        $id = I('id',0);
        if(empty($id))$this->ajaxReturn(['status'=>-1,'msg'=>'参数错误']);
        $return_goods = M('return_goods')->where(array('id'=>$id,'user_id'=>$this->user_id))->find();
        if(empty($return_goods)) $this->ajaxReturn(['status'=>-1,'msg'=>'参数错误']);
        $res= M('return_goods')->where(array('id'=>$id))->save(array('status'=>-2,'canceltime'=>time()));
        if ($res !== false){
            $this->ajaxReturn(['status'=>1,'msg'=>'取消成功']);
        }else{
            $this->ajaxReturn(['status'=>-1,'msg'=>'取消失败']);
        }
    }
    /**
     * 换货商品确认收货
     * @author lxl
     * @time  17-4-25
     * */
    public function receiveConfirm(){
        $return_id=I('return_id/d');
        $return_info=M('return_goods')->field('order_id,order_sn,goods_id,spec_key')->where('id',$return_id)->find(); //查找退换货商品信息
        $update = M('return_goods')->where('id',$return_id)->save(['status'=>3]);  //要更新状态为已完成
        if($update) {
            M('order_goods')->where(array(
                'order_id' => $return_info['order_id'],
                'goods_id' => $return_info['goods_id'],
                'spec_key' => $return_info['spec_key']))->save(['is_send' => 2]);  //订单商品改为已换货
            $this->success("操作成功", U("Order/return_goods_info", array('id' => $return_id)));
        }
        $this->error("操作失败");
    }

    /**
     *  评论晒单
     * @return mixed
     */
    public function comment()
    {
        $user_id = $this->user_id;
        $status = I('get.status');
        $logic = new CommentLogic;
        $data = $logic->getComment($user_id, $status); //获取评论列表

        $this->assign('page', $data['page']);// 赋值分页输出
        $this->assign('comment_page', $data['page']);
        $this->assign('comment_list', $data['result']);
        $this->assign('active', 'comment');
        if(I('is_ajax')){
            return $this->fetch('ajax_comment_list');
        }
        return $this->fetch();
    }

    /**
     *添加评论
     */
    public function add_comment()
    {
        if (IS_POST) {
            // 晒图片
            $files = request()->file('comment_img_file');
            $save_url = UPLOAD_PATH.'comment/' . date('Y', time()) . '/' . date('m-d', time());
            if($files) {
                $ossLogic = new OssLogic();
                foreach ($files as $v) {
                    $info = $v->getInfo();
                    $ext = substr( $info['name'],strripos($info['name'],'.') );
                    $new_name = get13TimeStamp().getRandChar(2).$ext;
                    $res =  $ossLogic->uploadFile(
                        $info['tmp_name'] , config('aliyun_oss.img_object').'goods_comment/'.date('Ymd').'/'.$new_name
                    );
                    if ($res != false) {
                        $comment_img[]=  $ossLogic->getSiteUrl().$res;
                    }
                }
            }

            if (!empty($comment_img)) {
                $add['img'] = serialize($comment_img);
            }

            $user_info = session('user');
            $logic = new UsersLogic();
            $add['rec_id'] = I('rec_id/d');
            $add['goods_id'] = I('goods_id/d');
            $add['email'] = $user_info['email'] ?? '';
            $hide_username = I('hide_username');
            if (empty($hide_username)) {
                $add['username'] = $user_info['nickname'];
            }
            $add['is_anonymous'] = $hide_username;  //是否匿名评价:0不是\1是
            $add['order_id'] = I('order_id/d');
            $add['service_rank'] = I('service_rank');
            $add['deliver_rank'] = I('deliver_rank');
            $add['goods_rank'] = I('goods_rank');
            $add['is_show'] = 1; //默认显示
            //$add['content'] = htmlspecialchars(I('post.content'));
            $add['content'] = I('content');
            $add['add_time'] = time();
            $add['ip_address'] = request()->ip();
            $add['user_id'] = $this->user_id;

            //添加评论
            $row = $logic->add_comment($add);
            if ($row['status'] == 1) {
                $this->redirect('Mobile/Order/comment',array('status'=>1));
                exit();
            } else {
                $this->error($row['msg']);
            }
        }
        $rec_id = I('rec_id/d');
        $order_goods = M('order_goods og')->field('og.*,g.original_img')->join('goods g','og.goods_id = g.goods_id','left')->where("rec_id", $rec_id)->find();
        $order_info = Db::name('order')->where("order_id", $order_goods['order_id'])->find();
        $this->assign('order_goods', $order_goods);
        $this->assign('order_info', $order_info);
        return $this->fetch();
    }

    /**
     * 待收货列表
     * @author lxl
     * @time   2017/1
     */
    public function wait_receive()
    {
        $where = ' user_id=' . $this->user_id;
        //条件搜索
        if (I('type') == 'WAITRECEIVE') {
            $where .= C(strtoupper(I('type')));
        }
        $count = M('order')->where($where)->count();
        $pagesize = C('PAGESIZE');
        $Page = new Page($count, $pagesize);
        $show = $Page->show();
        $order_str = "order_id DESC";
        $order_list = M('order')->order($order_str)->where($where)->limit($Page->firstRow . ',' . $Page->listRows)->select();
        //获取订单商品
        $model = new UsersLogic();
        foreach ($order_list as $k => $v) {
            $order_list[$k] = set_btn_order_status($v);  // 添加属性  包括按钮显示属性 和 订单状态显示属性
            $data = $model->get_order_goods($v['order_id']);
            $order_list[$k]['goods_list'] = $data['result'];
        }

        //统计订单商品数量
        foreach ($order_list as $key => $value) {
            $count_goods_num = 0;
            foreach ($value['goods_list'] as $kk => $vv) {
                $count_goods_num += $vv['goods_num'];
            }
            $order_list[$key]['count_goods_num'] = $count_goods_num;
            //订单物流单号
            $invoice_no = M('DeliveryDoc')->where("order_id", $value['order_id'])->getField('invoice_no', true);
            $order_list[$key][invoice_no] = implode(' , ', $invoice_no);
        }
        $this->assign('page', $show);
        $this->assign('order_list', $order_list);
        if ($_GET['is_ajax']) {
            return $this->fetch('ajax_wait_receive');
            exit;
        }
        return $this->fetch();
    }

    /**
     * 评论详情
     * @return mixed
     */
    public function comment_info(){
        $commentLogic = new \app\common\logic\CommentLogic;
        $comment_id = I('comment_id/d');
        $res = $commentLogic->getCommentInfo($comment_id);
        if(empty($res)){
            $this->error('参数错误！！');
        }
        $user_id = $res['comment_info']['user_id'];
        if(!empty($res['comment_info']['img'])) $res['comment_info']['img'] = unserialize($res['comment_info']['img']);
        $user = (new Users())->field('nickname,head_pic')->where('user_id',$user_id)->find();
        $res['comment_info']['nickname'] = $user['nickname'];
        $this->assign('comment_info',$res['comment_info']);
        $this->assign('comment_id',$res['comment_info']['comment_id']);
        $this->assign('reply',$res['reply']);
        $this->assign('user',$user);
        return $this->fetch();
    }

    /**
     * 评论别的用户评论
     */
    public function replyComment(){
        $data=I('post.');
        $data['user_id'] = $this->user_id;
        $data['user_name'] = $this->user['nickname'];
        $data['user_head_img'] = $this->user['head_pic'];
        $data['to_user_id'] = Db::name('comment')->where(['comment_id'=>$data['comment_id']])->getField('user_id');
        $data['to_user_name'] = Db::name('comment')->where(['comment_id'=>$data['comment_id']])->getField('username');
        $data['reply_time'] = time();
        $data['deleted'] = 0;
        $return = Db::name('reply')->add($data);
        if($return){
            Db::name('comment')->where(['comment_id'=>$data['comment_id']])->setInc('reply_num');
            $data['reply_time'] = date('Y-m-d H:i:s',$data['reply_time']);
            $this->ajaxReturn(['status'=>1,'msg'=>'评论成功！','result'=>$data]);
            exit;
        } else {
            $this->ajaxReturn(['status'=>0,'msg'=>"评论失败"]);
        }
    }

    /**
     *  点赞
     */
    public function ajaxZan()
    {
        $comment_id = I('post.comment_id/d');
        $user_id = $this->user_id;
        $comment_info = M('comment')->where(array('comment_id' => $comment_id))->find();  //获取点赞用户ID
        $comment_user_id_array = explode(',', $comment_info['zan_userid']);
        if (in_array($user_id, $comment_user_id_array)) {  //判断用户有没点赞过
            $result = ['status' => 0, 'msg' => '您已经点过赞了~', 'result' => ''];
        } else {
            array_push($comment_user_id_array, $user_id);  //加入用户ID
            $comment_user_id_string = implode(',', $comment_user_id_array);
            $comment_data['zan_num'] = $comment_info['zan_num'] + 1;  //点赞数量加1
            $comment_data['zan_userid'] = $comment_user_id_string;
            M('comment')->where(array('comment_id' => $comment_id))->save($comment_data);
            $result = ['status' => 1, 'msg' => '点赞成功~', 'result' => ''];
        }
        exit(json_encode($result));
    }



    /**
     * Author:赵磊
     * 确定拼团商品收货成功
     */
    public function teamOrderConfirm()
    {
        $id = I('id/d', 0);
        $data = confirm_order($id, $this->user_id);
        if(request()->isAjax()){
            $this->ajaxReturn($data);
        }
        if ($data['status'] != 1) {
            $this->error($data['msg'],U('Mobile/team/myTeam'));
        } else {
            $model = new UsersLogic();
            $order_goods = $model->get_order_goods($id);
            $this->assign('order_goods', $order_goods);
            return $this->fetch();
            exit;
        }
    }



    public function test()
    {
        //是否首单,首单给上级发券
            (new ActivityLogic())->firstOrderCoupon($this->user_id);
        //是否首单,首单给上级发券-end-
    }

}