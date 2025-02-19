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
 * Author: IT宇宙人      
 * 
 * Date: 2016-03-09
 */

namespace app\admin\controller;
use think\Page;
use app\admin\logic\GoodsLogic;
use think\Db;

class Distribut extends Base {
    
    /*
     * 初始化操作
     */
    public function _initialize() {
       parent::_initialize();
    }    
    
    /**
     * 分销树状关系
     */
    public function tree(){                
        $where = 'is_distribut = 1 and first_leader = 0';
        if($this->request->param('user_id'))
            $where = "user_id = '{$this->request->param('user_id')}'";
        
        $list = M('users')->where($where)->select();        
        $this->assign('list',$list);
        return $this->fetch();
    }
 
    /**
     * 分销商列表
     */
    public function distributor_list(){
    	$condition['is_distribut']  = 1;
    	$nickname = trim(I('nickname'));
    	$user_id = trim(I('user_id'));
    	if(!empty($nickname)){
    		$condition['nickname'] = array('like',"%$nickname%");
    	}
        if(!empty($user_id)){
            $condition['user_id'] = array('like',"%$user_id%");
        }
    	$count = M('users')->where($condition)->count();
    	$Page = new Page($count,10);
    	$show = $Page->show();
    	$user_list = M('users')->where($condition)->order('distribut_money DESC')->limit($Page->firstRow.','.$Page->listRows)->select();
    	foreach ($user_list as $k=>$val){
    		$user_list[$k]['fisrt_leader'] = M('users')->where(array('first_leader'=>$val['user_id']))->count();
    		$user_list[$k]['second_leader'] = M('users')->where(array('second_leader'=>$val['user_id']))->count();
    		$user_list[$k]['third_leader'] = M('users')->where(array('third_leader'=>$val['user_id']))->count();
//    		$user_list[$k]['lower_sum'] = $user_list[$k]['fisrt_leader'] +$user_list[$k]['second_leader'] + $user_list[$k]['third_leader'];
    	}
    	$this->assign('page',$show);
    	$this->assign('pager',$Page);
    	$this->assign('user_list',$user_list);
    	return $this->fetch();
    }
    
    /**
     * 分销设置
     */
    public function setting(){    
    	if(IS_POST){
    		$param = I('post.');
    		$inc_type = $param['inc_type'];
    		unset($param['inc_type']);
    		tpCache($inc_type,$param);
    		$this->success("操作成功");
    	}
    	$group_list = [
    		'distribut' => '分销设置','grade_list' => '分销商等级','agent_list' => '区域代理商','partner_list' => '全球股东',
    	];
    	$this->assign('group_list',$group_list);
    	$inc_type =  I('get.inc_type','distribut');
    	$this->assign('inc_type',$inc_type);
    	$config = tpCache($inc_type);
    	$this->assign('config',$config);//当前配置项
    	return $this->fetch();
    }
    
    public function grade_list(){
    	$grade_list = M('distribut_level')->select();
    	$this->assign('grade_list',$grade_list);
    	$inc_type =  'distribut';
    	$this->assign('inc_type',$inc_type);
    	$config = tpCache($inc_type);
    	$this->assign('config',$config);//当前配置项
    	return $this->fetch();
    }
    
    public function grade_info(){
    	$level_id = I('level_id',0);
    	if($level_id>0){
    		$info = M('distribut_level')->where(array('level_id'=>$level_id))->find();
    		$this->assign('info',$info);
    	}
    	return $this->fetch();
    }
    
    public function gradeInfoSave(){
    	$data =  I('post.');
    	if($data['level_id'] >0 ){
    		$r = M('distribut_level')->where(array('level_id'=>$data['level_id']))->save($data);
    	}else{
    		unset($data['level_id']);
    		$r = M('distribut_level')->add($data);
    	}
    	if($r){
    		$this->ajaxReturn(array('status'=>1,'msg'=>'操作成功'));
    	}else{
    		$this->ajaxReturn(array('status'=>0,'msg'=>'操作失败'));
    	}
    }
    
    public function goods_list(){
    	$GoodsLogic = new GoodsLogic();
    	$brandList = $GoodsLogic->getSortBrands();
    	$categoryList = $GoodsLogic->getSortCategory();
    	$this->assign('categoryList',$categoryList);
    	$this->assign('brandList',$brandList);
    	$where = ' commission > 0 ';
    	$cat_id = I('cat_id/d');
        $bind = array();
    	if($cat_id > 0)
    	{
    		$grandson_ids = getCatGrandson($cat_id);
    		$where .= " and cat_id in(".  implode(',', $grandson_ids).") "; // 初始化搜索条件
    	}
    	$key_word = I('key_word') ? trim(I('key_word')) : '';
    	if($key_word)
    	{
    		$where = "$where and (goods_name like :key_word1 or goods_sn like :key_word2)" ;
            $bind['key_word1'] = "%$key_word%";
            $bind['key_word2'] = "%$key_word%";
    	}
        $brand_id = I('brand_id');
        if($brand_id){
            $where = "$where and brand_id = :brand_id";
            $bind['brand_id'] = $brand_id;
        }
    	$model = M('Goods');
    	$count = $model->where($where)->bind($bind)->count();
    	$Page  = new Page($count,10);
    	$show = $Page->show();
    	$goodsList = $model->where($where)->bind($bind)->order('sales_sum desc')->limit($Page->firstRow.','.$Page->listRows)->select();
        $catList = D('goods_category')->select();
        $catList = convert_arr_key($catList, 'id');
        $this->assign('catList',$catList);
        $this->assign('pager',$Page);
    	$this->assign('goodsList',$goodsList);
    	$this->assign('page',$show);
    	return $this->fetch();
    }
 

    
    /**
     * 分成记录
     */
    public function rebate_log()
    { 
        $model = M("rebate_log"); 
        $status = I('status');
        $user_id = I('user_id/d');
        $order_sn = I('order_sn');        
        $create_time = I('create_time');
        $create_time = $create_time  ? $create_time  : date('Y-m-d',strtotime('-1 year')).' - '.date('Y-m-d',strtotime('+1 day'));
                       
        $create_time2 = explode(' - ',$create_time);
        $where = " create_time >= '".strtotime($create_time2[0])."' and create_time <= '".strtotime($create_time2[1])."' ";
        
        if($status === '0' || $status > 0)
            $where .= " and status = $status ";        
        $user_id && $where .= " and user_id = $user_id ";
        $order_sn && $where .= " and order_sn like '%{$order_sn}%' ";
                        
        $count = $model->where($where)->count();
        $Page  = new Page($count,16);        
        $list = $model->where($where)->order("id desc")->limit($Page->firstRow.','.$Page->listRows)->select();
        if(!empty($list)){
        	$get_user_id = get_arr_column($list, 'user_id'); // 获佣用户
        	$buy_user_id = get_arr_column($list, 'buy_user_id'); //购买用户
        	$user_id_arr = array_merge($get_user_id,$buy_user_id);
        	$user_arr = M('users')->where("user_id in (".  implode(',', $user_id_arr).")")->getField('user_id,mobile,nickname,email');
        	$this->assign('user_arr',$user_arr);
        }
        $this->assign('create_time',$create_time);        
        $show  = $Page->show();                 
        $this->assign('show',$show);
        $this->assign('list',$list);
        C('TOKEN_ON',false);
        return $this->fetch();
    }
    
    /**
     * 获取某个人下级元素
     */    
    public  function ajax_lower()
    {
        $id = $this->request->param('id');
        $list = M('users')->where("first_leader =".$id)->select();
        $this->assign('list',$list);
        return $this->fetch();
    }
    
    /**
     * 修改编辑 分成 
     */
    public  function editRebate(){        
        $id = I('id');
        $rebate_log = DB::name('rebate_log')->where('id',$id)->find();
        if (IS_POST) {
            $data = I('post.');
            // 如果是确定分成 将金额打入分佣用户余额
            if ($data['status'] == 3 && $rebate_log['status'] != 3) {
                accountLog($data['user_id'], $rebate_log['money'], 0, "订单:{$rebate_log['order_sn']}分佣", $rebate_log['money']);
            }
            DB::name('rebate_log')->update($data);
            $this->success("操作成功!!!", U('Admin/Distribut/rebate_log'));
            exit;
        }                      
       
       $user = M('users')->where("user_id = {$rebate_log[user_id]}")->find();       
            
       if($user['nickname'])        
           $rebate_log['user_name'] = $user['nickname'];
       elseif($user['email'])        
           $rebate_log['user_name'] = $user['email'];
       elseif($user['mobile'])        
           $rebate_log['user_name'] = $user['mobile'];            
       
       $this->assign('user',$user);
       $this->assign('rebate_log',$rebate_log);
       return $this->fetch();
    }

    /**
     * 删除分销商等级
     */
    public function delgrade(){
        $level_id =I('post.id/d',0);
        $r = Db::name('distribut_level')->where(['level_id'=>$level_id])->delete();  //先直接干掉
        if($r){
            $this->ajaxReturn(array('status'=>1,'msg'=>'操作成功'));
        }else{
            $this->ajaxReturn(array('status'=>0,'msg'=>'操作失败'));
        }
    }
}