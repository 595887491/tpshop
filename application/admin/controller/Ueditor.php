<?php
/**
 * tpshop
 * ============================================================================
 * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 商业用途务必到官方购买正版授权, 使用盗版将严厉追究您的法律责任。
 * ============================================================================
 * Author: 当燃      
 * Date: 2015-09-17
 */

namespace app\admin\controller;

use common\util\File;
use think\log;
use think\Image;
use think\Request;
use app\common\logic\EditorLogic;

/**
 * Class UeditorController
 * @package Admin\Controller
 */
class Ueditor extends Base
{
    private $sub_name = array('date', 'Y/m-d');
    private $savePath = 'temp/';

    public function __construct()
    {
        parent::__construct();
        
        //header('Access-Control-Allow-Origin: http://www.baidu.com'); //设置http://www.baidu.com允许跨域访问
        //header('Access-Control-Allow-Headers: X-Requested-With,X_Requested_With'); //设置允许的跨域header
        
        date_default_timezone_set("Asia/Shanghai");

        $savePath = I('savepath') ?: I('savePath');
        $this->savePath = $savePath ? $savePath . '/' : 'temp/';

        error_reporting(E_ERROR | E_WARNING);
        
        header("Content-Type: text/html; charset=utf-8");
    }
    
	public function index(){
        $CONFIG2 = json_decode(preg_replace("/\/\*[\s\S]+?\*\//", "", file_get_contents("./public/plugins/Ueditor/php/config.json")), true);
        $action = $_GET['action'];

        switch ($action) {
            case 'config':
                $result =  json_encode($CONFIG2);
                break;
            /* 上传图片 */
            case 'uploadimage':
		        $result = $this->imageUp(true);
		        break;
            /* 上传涂鸦 */
            case 'uploadscrawl':
		        $config = array(
		            "pathFormat" => $CONFIG2['scrawlPathFormat'],
		            "maxSize" => $CONFIG2['scrawlMaxSize'],
		            "allowFiles" => $CONFIG2['scrawlAllowFiles'],
		            "oriName" => "scrawl.png"
		        );
		        $fieldName = $CONFIG2['scrawlFieldName'];
		        $base64 = "base64";
		        $result = $this->upBase64($config,$fieldName);
		        break;
            /* 上传视频 */
            case 'uploadvideo':
		        $fieldName = $CONFIG2['videoFieldName'];
		        $result = $this->upFile($fieldName);
		        break;
            /* 上传文件 */
            case 'uploadfile':
		        $fieldName = $CONFIG2['fileFieldName'];
		        $result = $this->upFile($fieldName);
                break;
            /* 列出图片 */
            case 'listimage':
			    $allowFiles = $CONFIG2['imageManagerAllowFiles'];
			    $listSize = $CONFIG2['imageManagerListSize'];
			    $path = $CONFIG2['imageManagerListPath'];
			    $get =$_GET;
			    $result =$this->fileList($allowFiles,$listSize,$get);
                break;
            /* 列出文件 */
            case 'listfile':
			    $allowFiles = $CONFIG2['fileManagerAllowFiles'];
			    $listSize = $CONFIG2['fileManagerListSize'];
			    $path = $CONFIG2['fileManagerListPath'];
			    $get = $_GET;
			    $result = $this->fileList($allowFiles,$listSize,$get);
                break;
            /* 抓取远程文件 */
            case 'catchimage':
		    	$config = array(
			        "pathFormat" => $CONFIG2['catcherPathFormat'],
			        "maxSize" => $CONFIG2['catcherMaxSize'],
			        "allowFiles" => $CONFIG2['catcherAllowFiles'],
			        "oriName" => "remote.png"
			    );
			    $fieldName = $CONFIG2['catcherFieldName'];
			    /* 抓取远程图片 */
			    $list = array();
			    isset($_POST[$fieldName]) ? $source = $_POST[$fieldName] : $source = $_GET[$fieldName];

                $sources = explode('|',$source);
                $url = '';
			    foreach($sources as $imgUrl){
			        $info = json_decode($this->saveRemote($config,$imgUrl),true);
			        $url .= '|'.$info["url"];
			        array_push($list, array(
			            "state" => $info["state"],
			            "url" => $info["url"],
			            "size" => $info["size"],
			            "title" => htmlspecialchars($info["title"]),
			            "original" => htmlspecialchars($info["original"]),
			            "source" => htmlspecialchars($imgUrl)
			        ));
			    }

			    $result = json_encode(array(
			        'state' => count($list) ? 'SUCCESS':'ERROR',
			        'srcUrl' => $source,
			        'url' => trim($url,'|')
			    ));
                break;
            default:
                $result = json_encode(array(
                    'state' => '请求地址出错'
                ));
                break;
        }

        /* 输出结果 */
        if(isset($_GET["callback"])){
            if(preg_match("/^[\w_]+$/", $_GET["callback"])){
                echo htmlspecialchars($_GET["callback"]).'('.$result.')';
            }else{
                echo json_encode(array(
                    'state' => 'callback参数不合法'
                ));
            }
        }else{
            echo $result;
        }
	}
	
	//上传文件
	private function upFile($fieldName)
    {
		$file = request()->file('file');
		if(empty($file)){
			$file = request()->file('upfile');
		}
		if (empty($file)) {
			$state = "ERROR";
            return json_encode(['state' =>$state]);
		} elseif (strtolower(pathinfo($file->getInfo('name'), PATHINFO_EXTENSION)) == 'php') {
            return json_encode(['state' =>'ERROR'.'后缀不允许']);
        }

        // 移动到框架应用根目录/public/uploads/ 目录下
        $this->savePath = $this->savePath.date('Y').'/'.date('m-d').'/';
        // 使用自定义的文件保存规则
        $info = $file->rule(function ($file) {
            return  md5(mt_rand());
        })->move(UPLOAD_PATH.$this->savePath);

		if($info){
			$data = array(
				'state' => 'SUCCESS',
				'url' => '/'.UPLOAD_PATH.$this->savePath.$info->getSaveName(),
				'title' => $info->getFilename(),
				'original' => $info->getFilename(),
				'type' => '.' . $info->getExtension(),
				'size' => $info->getSize(),
			);
			//图片加水印
            //图片应该不会走这个函数(走imageUp)，为了避免还有调用，先屏蔽。 by lhb
//			if($this->savePath=='goods/'){
//        		$imgresource = ".".$data['url'];
//        		$image = \think\Image::open($imgresource);
//        		$water = tpCache('water');
//        		//$image->open($imgresource);
//        		$return_data['mark_type'] = $water['mark_type'];
//        		if($water['is_mark']==1 && $image->width()>$water['mark_width'] && $image->height()>$water['mark_height']){
//        			if($water['mark_type'] == 'text'){
//        				//$image->text($water['mark_txt'],'./hgzb.ttf',20,'#000000',9)->save($imgresource);
//        				$ttf = './hgzb.ttf';
//        				if (file_exists($ttf)) {
//        					$size = $water['mark_txt_size'] ? $water['mark_txt_size'] : 30;
//        					$color = $water['mark_txt_color'] ?: '#000000';
//        					if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
//        						$color = '#000000';
//        					}
//        					$transparency = intval((100 - $water['mark_degree']) * (127/100));
//        					$color .= dechex($transparency);
//        					$image->open($imgresource)->text($water['mark_txt'], $ttf, $size, $color, $water['sel'])->save($imgresource);
//        					$return_data['mark_txt'] = $water['mark_txt'];
//        				}
//        			}else{
//        				//$image->water(".".$water['mark_img'],9,$water['mark_degree'])->save($imgresource);
//        				$waterPath = "." . $water['mark_img'];
//        				$quality = $water['mark_quality'] ? $water['mark_quality'] : 80;
//        				$waterTempPath = dirname($waterPath).'/temp_'.basename($waterPath);
//        				$image->open($waterPath)->save($waterTempPath, null, $quality);
//        				$image->open($imgresource)->water($waterTempPath, $water['sel'], $water['mark_degree'])->save($imgresource);
//        				@unlink($waterTempPath);
//        			}
//        		}
//        	}
		}else{
			$data = array('state' => 'ERROR'.$file->getError());
		}
		return json_encode($data);
	}

    //列出图片
	private function fileList($allowFiles,$listSize,$get)
    {
	    $savePath = '';
	    if ($this->savePath && $this->savePath != 'temp/') {
	        $savePath = $this->savePath;
        }
		$dirname = './'.UPLOAD_PATH.$savePath;
		$allowFiles = substr(str_replace(".","|",join("",$allowFiles)),1);
		/* 获取参数 */
		$size = isset($get['size']) ? htmlspecialchars($get['size']) : $listSize;
		$start = isset($get['start']) ? htmlspecialchars($get['start']) : 0;
		$end = $start + $size;
		/* 获取文件列表 */
		$path = $dirname;
		$files = $this->getFiles($path,$allowFiles);
		if(!count($files)){
		    return json_encode(array(
		        "state" => "no match file",
		        "list" => array(),
		        "start" => $start,
		        "total" => count($files)
		    ));
		}
		/* 获取指定范围的列表 */
		$len = count($files);
		for($i = min($end, $len) - 1, $list = array(); $i < $len && $i >= 0 && $i >= $start; $i--){
		    $list[] = $files[$i];
		}

		/* 返回数据 */
		$result = json_encode(array(
		    "state" => "SUCCESS",
		    "list" => $list,
		    "start" => $start,
		    "total" => count($files)
		));

		return $result;
	}

   	/*
	 * 遍历获取目录下的指定类型的文件
	 * @param $path
	 * @param array $files
	 * @return array
	*/
    private function getFiles($path,$allowFiles,&$files = array()){
	    if(!is_dir($path)) return null;
	    if(substr($path,strlen($path)-1) != '/') $path .= '/';
	    $handle = opendir($path);
			
	    while(false !== ($file = readdir($handle))){
	        if($file != '.' && $file != '..'){
	            $path2 = $path.$file;
	            if(is_dir($path2)){
	                $this->getFiles($path2,$allowFiles,$files);
	            }else{
		            if(preg_match("/\.(".$allowFiles.")$/i",$file)){
		                $files[] = array(
		                    'url' => substr($path2,1),
		                    'mtime' => filemtime($path2)
		                );
		            }
	            }
	        }
	    }		
	    return $files;
    }

    //抓取远程图片
	private function saveRemote($config,$fieldName){
	    $imgUrl = htmlspecialchars($fieldName);
	    $imgUrl = str_replace("&amp;","&",$imgUrl);

	    //http开头验证
	    if(strpos($imgUrl,"http") !== 0){
	        $data=array(
		        'state' => '链接不是http链接',
		    );
	        return json_encode($data);
	    }
	    //获取请求头并检测死链
	    $heads = get_headers($imgUrl);
	    if(!(stristr($heads[0],"200") && stristr($heads[0],"OK"))){
	        $data=array(
		        'state' => '链接不可用',
		    );
	        return json_encode($data);
	    }
	    //格式验证(扩展名验证和Content-Type验证)
//	    $fileType = strtolower(strrchr($imgUrl,'.'));
//	    if(!in_array($fileType,$config['allowFiles']) || stristr($heads['Content-Type'],"image")){
//	        $data=array(
//		        'state' => '链接contentType不正确',
//		    );
//	        return json_encode($data);
//	    }

	    //打开输出缓冲区并获取远程图片
	    ob_start();
	    $context = stream_context_create(
	        array('http' => array(
	            'follow_location' => false // don't follow redirects
	        ))
	    );
	    readfile($imgUrl,false,$context);
	    $img = ob_get_contents();
	    ob_end_clean();
	    preg_match("/[\/]([^\/]*)[\.]?[^\.\/]*$/",$imgUrl,$m);

	    $dirname = UPLOAD_PATH.'remote/';
	    $file['oriName'] = $m ? $m[1] : "";
	    $file['filesize'] = strlen($img);
	    $file['ext'] = strtolower(strrchr($config['oriName'],'.'));
	    $file['name'] = uniqid().$file['ext'];
	    $file['fullName'] = $dirname.$file['name'];
	    $fullName = $file['fullName'];

	    //检查文件大小是否超出限制
	    if($file['filesize'] >= ($config["maxSize"])){
  		    $data=array(
			    'state' => '文件大小超出网站限制',
		    );
		    return json_encode($data);
	    }

	    //创建目录失败
	    if(!file_exists($dirname) && !mkdir($dirname,0777,true)){
  		    $data=array(
			    'state' => '目录创建失败',
		    );
		    return json_encode($data);
	    }else if(!is_writeable($dirname)){
  		    $data=array(
			    'state' => '目录没有写权限',
		    );
		    return json_encode($data);
	    }

	    //移动文件
	    if(!(file_put_contents($fullName, $img) && file_exists($fullName))){ //移动失败
  		    $data=array(
			    'state' => '写入文件内容错误',
		    );
		    return json_encode($data);
	    }else{ //移动成功

            $ossClient = new \app\common\logic\OssLogic;
            $return_url = $ossClient->uploadFile('./'.$file['fullName'], $file['fullName']);

            if (!$return_url) {
                $data=array(
                    'state' => $ossClient->getError(),
                );
                return json_encode($data);
            }
            @unlink('./'.$file['fullName']);

	        $data=array(
			    'state' => 'SUCCESS',
			    'url' => config('aliyun_oss.Oss_cdn').$return_url,
			    'title' => $file['name'],
			    'original' => $file['oriName'],
			    'type' => $file['ext'],
			    'size' => $file['filesize'],
		    );
	    }
		
	    return json_encode($data);
	}

    /*
	 * 处理base64编码的图片上传
	 * 例如：涂鸦图片上传
	*/
	private function upBase64($config,$fieldName){
	    $base64Data = $_POST[$fieldName];
	    $img = base64_decode($base64Data);

	    $dirname = UPLOAD_PATH.'scrawl/';
	    $file['filesize'] = strlen($img);
	    $file['oriName'] = $config['oriName'];
	    $file['ext'] = strtolower(strrchr($config['oriName'],'.'));
	    $file['name'] = uniqid().$file['ext'];
	    $file['fullName'] = $dirname.$file['name'];
	    $fullName = $file['fullName'];

 	    //检查文件大小是否超出限制
	    if($file['filesize'] >= ($config["maxSize"])){
  		    $data=array(
			    'state' => '文件大小超出网站限制',
		    );
		    return json_encode($data);
	    }

	    //创建目录失败
	    if(!file_exists($dirname) && !mkdir($dirname,0777,true)){
	        $data=array(
			    'state' => '目录创建失败',
		    );
		    return json_encode($data);
	    }else if(!is_writeable($dirname)){
	        $data=array(
			    'state' => '目录没有写权限',
		    );
		    return json_encode($data);
	    }

	    //移动文件
	    if(!(file_put_contents($fullName, $img) && file_exists($fullName))){ //移动失败
            $data=array(
		        'state' => '写入文件内容错误',
		    );
	    }else{ //移动成功	       
	        $data=array(
			    'state' => 'SUCCESS',
			    'url' => substr($file['fullName'],1),
			    'title' => $file['name'],
			    'original' => $file['oriName'],
			    'type' => $file['ext'],
			    'size' => $file['filesize'],
		    );
	    }
		
	    return json_encode($data);
	}

    /**
     * @function imageUp
     */
    public function imageUp($ueditor = '')
    {
        // 上传图片框中的描述表单名称，
        $pictitle = I('pictitle');
        $dir = I('dir');
        $title = htmlspecialchars($pictitle , ENT_QUOTES);        
        $path = htmlspecialchars($dir, ENT_QUOTES);
        //$input_file ['upfile'] = $info['Filedata'];  一个是上传插件里面来的, 另外一个是 文章编辑器里面来的
        // 获取表单上传文件
        $file = request()->file('file');
        $editor = new EditorLogic;

        if (empty($file)) {
            $file = request()->file('upfile');
        }
        $result = $this->validate(
            ['file' => $file],
            ['file'=>'image|fileSize:40000000|fileExt:jpg,jpeg,gif,png'],
            ['file.image' => '上传文件必须为图片','file.fileSize' => '上传文件过大','file.fileExt'=>'上传文件后缀名必须为jpg,jpeg,gif,png']
           );
        if (true !== $result || !$file) {
            $state = "ERROR" . $result;
        } else {
            $return = $editor->saveUploadImage($file, $this->savePath);
            $state = $return['state'];
            if ($ueditor) {
                $return_data['url'] = config('aliyun_oss.Oss_cdn').$return['url'].'?x-oss-process=style/s750x750';
            }else{
                $return_data['url'] = config('aliyun_oss.Oss_cdn').$return['url'];
            }
        }

        $return_data['title'] = $title;
        $return_data['original'] = ''; // 这里好像没啥用 暂时注释起来
        $return_data['state'] = $state;
        $return_data['path'] = $path;

        $this->ajaxReturn($return_data);
    }
    
    /**
     * app文件上传
     */
    public function appFileUp()
    {      
        $path = UPLOAD_PATH.'appfile/';
        if (!file_exists($path)) {
            mkdir($path);
        }

        //$input_file  ['upfile'] = $info['Filedata'];  一个是上传插件里面来的, 另外一个是 文章编辑器里面来的
        // 获取表单上传文件
        $file = request()->file('Filedata');
        if (empty($file)) {
            $file = request()->file('upfile');    
        }
        
        $result = $this->validate(
            ['file2' => $file], 
            ['file2'=>'fileSize:30000000|fileExt:apk,ipa,pxl,deb'],
            ['file2.fileSize' => '上传文件过大', 'file2.fileExt' => '上传文件后缀不正确']                    
           );
        if (true !== $result || empty($file)) {            
            $state = "ERROR" . $result;
        } else {
            $info = $file->rule(function ($file) {    
                return date('YmdHis_').input('Filename'); // 使用自定义的文件保存规则
            })->move($path);
            if ($info) {
                $state = "SUCCESS";                         
            } else {
                $state = "ERROR" . $file->getError();
            }
            $return_data['url'] = $path.$info->getSaveName();            
        }
        
        $return_data['title'] = 'app文件';
        $return_data['original'] = ''; // 这里好像没啥用 暂时注释起来
        $return_data['state'] = $state;
        $return_data['path'] = $path;

        $this->ajaxReturn($return_data);
    }

    /**
     * 微信公众号图片素材列表
     * @param $listSize int 拉取多少
     * @param $get array get数组
     * @return string
     */
    public function wechatImageList($listSize, $get)
    {
        $size = isset($get['size']) ? intval($get['size']) : $listSize;
        $start = isset($get['start']) ? intval($get['start']) : 0;

        $logic = new \app\common\logic\WechatLogic;
        return $logic->getPluginImages($size, $start);
    }

    /**
     * 上传视频
     */
    public function videoUp()
    {
        $pictitle = I('pictitle');
        $dir = I('dir');
        $title = htmlspecialchars($pictitle , ENT_QUOTES);
        $path = htmlspecialchars($dir, ENT_QUOTES);
        // 获取表单上传文件
        $file = request()->file('file');
        if (empty($file)) {
            $file = request()->file('upfile');
        }
        $result = $this->validate(
            ['file' => $file],
            ['file'=>'fileSize:40000000|fileExt:mp4,3gp,flv,avi,wmv'],
            ['file.fileSize' => '上传文件过大','file.fileExt'=>'上传文件后缀名必须为mp4,3gp,flv,avi,wmv']
        );
        if (true !== $result || !$file) {
            $state = "ERROR" . $result;
        } else {

            $editor = new EditorLogic;
            $result1 = $editor->saveUploadImage($file, $this->savePath);
            $state = $result1['state'];
            $return_data['url'] = $result1['url'];

        }

        $return_data['title'] = $title;
        $return_data['original'] = ''; // 这里好像没啥用 暂时注释起来
        $return_data['state'] = $state;
        $return_data['path'] = $path;

        $this->ajaxReturn($return_data);
    }
}