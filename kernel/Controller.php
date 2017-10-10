<?php
namespace Kernel;
use Itxiao6\View\Compilers\ViewCompiler;
use Itxiao6\View\Engines\CompilerEngine;
use Itxiao6\View\FileViewFinder;
use Itxiao6\View\Factory;
use Service\Exception;
use Service\Http;
use Service\DB;
use Service\Timeer;
use Service\Umeditor;

/**
 * 控制器父类
*/
class Controller
{

    /**
     * 视图数据
     * @var array
     */
    protected $viewData = [];

    /**
     * 是否渲染debugbar
     * @var bool
     */
    protected $debugbar = true;

    /**
     * 构造方法
     * Controller constructor.
     */
    public function __construct()
    {
        # 判断初始化函数属否定义
        if(method_exists($this,'__init')){
          $this -> __init();
        }
    }

    /**
     * 渲染模板
     * @param string $view
     * @param array $data
     * @return mixed
     */
    public function display($view='default',Array $data = [])
    {
        return $this -> getView($view,$data).$this -> debugbar();
    }

    /**
     * 获取模板内容
     * @param string $view 要渲染的模板名
     * @param array $data 要分配到模板引擎的变量
     * @return mixed 模板内容
     * @throws \Exception
     */
    public function getView($view='default',Array $data = [])
    {
        # 计时开始
        Timeer::start();
        # 循环处理模板目录
        foreach(Config::get('sys','view_path') as $value){
          # 判断模板目录是否存在
          if( !file_exists($value) ){
              Exception::message("模板目录不存在或没有权限".$value);
          }
        }
        # 判断模板编译目录是否存在并且有写入的权限
        if( (!file_exists(CACHE_VIEW)) or (!is_writable(CACHE_VIEW)) ){
            Exception::message("模板编译目录不存在或没有权限");
        }
        # 实例化View 的编译器
        $compiler = new ViewCompiler(CACHE_VIEW);

        # 实例化 编译器
        $engine = new CompilerEngine($compiler);
        # 实例化文件系统
        $finder = new FileViewFinder(Config::get('sys','view_path'),Config::get('sys','extensions'));
        # 实例化模板
        $factory = new Factory($engine,$finder);

        # 判断是否传入的模板名称
        if($view=='default'){
          $view = CONTROLLER_NAME.'.'.ACTION_NAME;
        }else if(!strpos($view,'/') && $view != 'success' && $view != 'error'){
          $view = CONTROLLER_NAME.'.'.$view;
        }else{
          $view = str_replace('/','.',$view);
        }
        # 判断是否开启了 debugbar
        if(Config::get('sys','debugbar')) {
            # 定义全局变量
            global $debugbar;
            # 记录渲染的模板
            $debugbar["View"]->addMessage($finder->find($view));
        }
        # 判断是否传了要分配的值
        if(count($data) > 0){
          # 分配模板的数组合并
          $this -> assign($data);
        }
        # 判断模板是否存在
        if(!$factory -> exists($view)){
          throw new \Exception('找不到 '.$view.' 模板');
        }
        # 解析模板
        $factory -> make($view,$this -> viewData);
        # 获取渲染的结果
        $result = $factory -> toString();
        # 计时开始
        Timeer::end('【View】'.$view.' 完成');
        return $result;
    }

    /**
     * 分配变量值
     * @param $key
     * @param array $data
     * @return $this
     */
    protected function assign($key,$data = []){
        # 判断传的是否为数组
        if( is_array($key) ){
            $this -> viewData = array_merge($this -> viewData, $key);
        }else{
            $this -> viewData = array_merge($this -> viewData,[$key => $data]);
        }
        # 返回本对象
        return $this;
    }

    # 百度编辑器上传文件
    public function umeditor_upload(){
        //上传配置
        $config = array(
            // 存储文件夹
            "savePath" => ROOT_PATH."public/upload/",
            // 允许的文件最大尺寸，单位KB
            "maxSize" => 3000,
            // 允许的文件格式
            "allowFiles" => array( ".gif" , ".png" , ".jpg" , ".jpeg" , ".bmp" )
        );
        # 实例化上传类
        $up = new Umeditor( "upfile" , $config );
        # 获取上传后的信息
        $info = $up->getFileInfo();
        # 返回信息
        exit(json_encode($info));
    }

    /**
    * Ajax方式返回数据到客户端
    * @access protected
    * @param mixed $data 要返回的数据
    * @param String $type AJAX返回数据格式
    * @param int $json_option 传递给json_encode的option参数
    * @return void
    */
    protected function ajaxReturn($data,$type='',$json_option=0) {
        if(empty($type)) $type  =   Config::get('sys','default_ajax_return');
        switch (strtoupper($type)){
            case 'JSON':
                # 返回JSON数据格式到客户端 包含状态信息
                header('Content-Type:application/json; charset=utf-8');
                return(json_encode($data,$json_option));
            case 'XML':
                # 返回xml格式数据
                header('Content-Type:text/xml; charset=utf-8');
                return(xml_encode($data));
            case 'JSONP':
                # 返回JSON数据格式到客户端 包含状态信息
                header('Content-Type:application/json; charset=utf-8');
                $handler  =   $_GET['jsonpCallback'];
                return($handler.'('.json_encode($data,$json_option).');');
            case 'EVAL':
                # 返回可执行的js脚本
                header('Content-Type:text/html; charset=utf-8');
                return($data);
            default:
                # 用于扩展其他返回格式数据
                return false;
        }
    }
    /**
    * Http重定向
    * @access protected
    * @param string $url 跳转的URL
    * @return void
    */
    protected function redirect($url) {
        Http::redirect($url);
    }

    /**
     * 调试面板
     * @return string
     */
    protected function debugbar(){
        $str = '';
        # 获取全局变量
        global $debugbar;
        global $debugbarRenderer;
        global $database;
        # 判断是否开启了数据库日志 并且数据库有查询语句
        if($database && is_array(DB::DB_LOG()) && Config::get('sys','debugbar')){
            # 遍历计时器事件
            foreach (Timeer::get_event() as $item) {
                $debugbar["Time"]
                    ->addMessage($item['message']);
            }
            # 遍历sql
            foreach (DB::DB_LOG() as $key => $value) {
                $debugbar["Database"]
                    ->addMessage('语句:'.$value['query'].' 耗时:'.$value['time'].' 参数:'.$value['bindings']);
            }
        }
        # 判断是否开启了 debugbar
        if(Config::get('sys','debugbar')){
            $str .= preg_replace('!\/vendor\/maximebf\/debugbar\/src\/DebugBar\/!','/',$debugbarRenderer->renderHead());
            $str .= $debugbarRenderer->render();
        }
        return $str;
    }

}