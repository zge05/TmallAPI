<?php
Yii::$enableIncludePath = false;
//发送邮件
Yii::import('application.components.Taobaomail') ;
Yii::import('application.components.TaobaoConnector') ;
Yii::import('application.extensions.PHPExcel.PHPExcel', 1);
require_once( dirname(__FILE__) . '/../components/ConsoleCommand.php' ) ;
include_once (dirname(__FILE__).'/../extensions/PHPExcel/PHPExcel/IOFactory.php');
PHPExcel_CachedObjectStorageFactory::cache_in_memory_serialized;
class CategoryProductCommand extends ConsoleCommand { 
    protected $PHPExcel = null;
    protected $PHPReader = null;
    protected $PHPWrite = null;
    protected $readFileName = null;
    protected $saveFileName = null;
    protected $_className= null ;
    //SKU、ITEM、EXCELTITLE的数组
    protected $skuArray = null;
    protected $itemArray = null;
    protected $titleArray = null;
    protected $_parentFields= array();
    protected $_skuFields= array();
    protected $fileName = null;
    protected $taobaomail = null;
    public function init(){
        ini_set('memory_limit', '800M');
        $this->PHPExcel = new PHPExcel_Reader_Excel5();
        $this->PHPWrite = new PHPExcel();
        $this->_className= get_class() ;
        $this->beforeAction( $this->_className, '') ;
        //数组初始化
        $this->_parentFields = array("num_iid","banner","title","outer_id","approve_status","num","skus");//skus must be the last one
        $this->_skuFields = array("sku_id","outer_id","bo_id","reginal_sales","quantity","with_hold_quantity","price","properties_name");
        $this->titleArray = array("num_iid","banner","title","item_outer_id","approve_status","num","sku_id","sku_outer_id","bo_id","reginal_sales","quantity","with_hold_quantity","price","properties");
        //发送邮件
        $this->taobaomail = new Taobaomail();
    }
    public function run($choic){
       	ob_start();
        $this->_prompt($choic);
        switch ($choic[0]) {
            case 'inventory':
                $this->fileName = "Inventory_sku.xls";
                $this->readFileName = dirname(__FILE__).'/../../Excel/Inventory_num_iid.xls';
                $this->saveFileName = dirname(__FILE__).'/../../Excel/'.$this->fileName;
                break;
            case 'onsale':
                $this->fileName = "Onsale_sku.xls";
                $this->readFileName = dirname(__FILE__).'/../../Excel/Onsale_num_iid.xls';
                $this->saveFileName = dirname(__FILE__).'/../../Excel/'.$this->fileName;
                break;
            default:
                echo "**************************************************\n"
                    . "Please input parameter : onsale or inventory\n"
                    . "**************************************************";
                exit();
        }
        $this->PHPReader = $this->PHPExcel->load($this->readFileName);
        fopen($this->saveFileName, "w+");
        $this->_generateExcel();
        //发送邮件
        $this->taobaomail->sendTaobaoMai($this->fileName);
    }
    //parameter prompt
    public function _prompt($args){
        if(empty($args)){
            echo "**************************************************\n"
            . "Please input parement : onsale or inventory\n"
            . "Like that:categoryproduct onsale\n"      
            . "**************************************************";
            exit();
        }
    }
    //获取API属性
    public function _getAPIValue($num_iid){
        //num_iid不存在则返回NULL
        $_itemsTmallAll= array();
        $_itemsTmall= $this->_connectTmall(Yii::app()->params['taobao_api']['accessToken'],$num_iid);
        if(!empty($_itemsTmall)){
            if (array_key_exists('item',$_itemsTmall['item_get_response'])){
                $_itemsTmallAll= $_itemsTmall['item_get_response']['item'];
            }
            return $_itemsTmallAll;
        }else{
            return $_itemsTmall;
        }
    }
    
    public function _filterApiParentValue($num_iid,$banner){
        $_filterResult= array();
        $_itemsTmallAll = $this->_getAPIValue($num_iid);
        if(!empty($_itemsTmallAll)){
            foreach ($this->_parentFields as $field){
                $_filterResult['banner']= $banner;
                if(array_key_exists($field,$_itemsTmallAll)){
                    $_filterResult[$field]= $_itemsTmallAll[$field];
                }else{
                    $_filterResult[$field]= "";
                }
            }
            unset($_itemsTmallAll);
            return $_filterResult;
        }else{
            Yii::log('Caught exception: num_iid:' .$num_iid. 'item not exists', 'error', 'system.fail');
            return false;
        }
    }
    
    public function _filterApiSkuValue($_filterParentResult,$num_iid){
        if(!empty($_filterParentResult['skus'])){
            if(array_key_exists('sku', $_filterParentResult['skus'])){
                foreach ($_filterParentResult['skus']['sku'] as $key=> $value){
                    foreach($this->_skuFields as $field){
                        if(!array_key_exists($field, $value)){
                            $_filterParentResult['skus']['sku'][$key][$field]= "";
                        }
                    }
                }
            }else{
                Yii::log('Caught exception: num_iid:' .$num_iid. 'sku not exists', 'error', 'system.fail');
            }
        }else{
            $_filterParentResult['skus']['sku']= array();
            Yii::log('Caught exception: num_iid:' .$num_iid. 'skus not exists', 'error', 'system.fail');
        }
        return $_filterParentResult;
    }
    
    public function _insertExc($_numID,$banner,$_row){
    	error_reporting( E_ALL&~E_NOTICE );
    	//对item_id判断
    	$sku_id = null;
    	$result = $this->_connectTmall_BO_ID_Reginal_Sales(Yii::app()->params['taobao_api']['accessToken'], $_numID, $sku_id);
    	$currentSheet = $this->PHPWrite->setActiveSheetIndex(0);
    	$_filterParentResult= $this->_filterApiParentValue($_numID,$banner);
    	$_filterResult = $this->_filterApiSkuValue($_filterParentResult, $_numID);
    	unset($_filterParentResult);
    	if(count($_filterResult['skus']['sku'])==0){
    		$_skuQty = 1;
    	}else{
    		$_skuQty = count($_filterResult['skus']['sku']);
    	}
    	for($i=0;$i<$_skuQty;$i++){
    		if (!empty($result)){
    			$sku_id = $_filterResult['skus']['sku'][$i]['sku_id'];
//     			$sku_id = $sku_id?$sku_id:-1;
    			$result2 = $this->_connectTmall_BO_ID_Reginal_Sales(Yii::app()->params['taobao_api']['accessToken'], $_numID, $sku_id);
    			if(!empty($result2)){
    				$_filterResult['skus']['sku'][$i]['bo_id'] = $result2['bo_id'];
    				$_filterResult['skus']['sku'][$i]['reginal_sales'] = $result2['reginal_sales'];
    			}
    		}
    		$index=65;
    		foreach ($this->_parentFields as $field){
    			if(!is_array($_filterResult[$field])){
    				$currentSheet->setCellValue(chr($index).($_row+$i),$_filterResult[$field]);
    				$index++;
    			}
    		}
    		foreach ($this->_skuFields as $sku) {
    			if (empty($_filterResult['skus']['sku'])){
    				$currentSheet->setCellValue(chr($index).($_row+$i),NULL);
    			}else{
    				$currentSheet->setCellValue(chr($index).($_row+$i),$_filterResult['skus']['sku'][$i][$sku]);
    			}
    			$index++;
    		}
    	}
    	$_newRow = $_row + $_skuQty;
    	return $_newRow;
    }
    
    public function _generateExcel(){
        ob_start();
        $this->_startSaveExcel();//Excel的头部
        $currentSheet = $this->PHPReader->getSheet(0);
        $allRow = $currentSheet->getHighestRow();
        //循环写入
        $rowIndex = 2;
        for($rowI=2;$rowI<=$allRow;$rowI++){
            $num_iid = $this->_readExcelData($rowI, 'A');
            $banner = $this->_readExcelData($rowI, 'B');
            if(!empty($num_iid)){
               $rowIndex = $this->_insertExc($num_iid,$banner,$rowIndex); 
            }
        }
        $this->_endSaveExcel();//Excel的尾部
        echo "\t$this->fileName\n-------------END-------------";
    }
    
   
    
    //读取Excel中的数据
    public function _readExcelData($rowIndex,$colIndex){
        //单元格位置
        $addr =$colIndex.$rowIndex;
        $cell = $this->PHPReader->setactivesheetindex(0)->getCell($addr)->getValue();
        return $cell;
    }
    
    
    //Excel的头部
    public function _startSaveExcel(){
        $currentSheet = $this->PHPWrite->setactivesheetindex(0);
        for($i=0,$index = 65;$i<count($this->titleArray);$i++,$index++){
            $currentSheet->setCellValue(chr($index)."1", $this->titleArray[$i]);    
        }
        $this->PHPWrite->setactivesheetindex(0)->setTitle("Sheet1");
    }
    
    
    //Excel的尾部
    public function _endSaveExcel(){ 
        if(!is_writable($this->saveFileName)){
            echo 'Can not Write';
            exit();
        }
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename='.$this->saveFileName);
        header('Cache-Control: max-age=0');
        //创建文件使用Excel2003版本
        $objWriter = PHPExcel_IOFactory::createWriter($this->PHPWrite,'Excel5');  
        $objWriter->save($this->saveFileName);
    }
   
    private function _connectTmall($_sessionkey,$num_iid){
        $_taobaoConnect= new TaobaoConnector();
        $_taobaoConnect->__url=Yii::app()->params['taobao_api']['url'] ;
        $_taobaoConnect->__appkey= Yii::app()->params['taobao_api']['appkey'] ;
        $_taobaoConnect->__appsecret= Yii::app()->params['taobao_api']['appsecret'] ;
        $_taobaoConnect->__method= Yii::app()->params['taobao_api']['methods']['commodity_method'] ;
        $_taobaoConnect->__fields= Yii::app()->params['taobao_api']['fields']['commodity_sku_field'] ;
        $_items= $_taobaoConnect->connectTaobaoSKU( $_sessionkey,$num_iid) ;
//         if (array_key_exists('error_response',$_items)){
//             Yii::log('Caught exception: ' . serialize($_items), 'error', 'system.fail');
//             return NULL;
//         }
        if (array_key_exists('item_get_response',$_items)){
                return $_items ;           
        }else{
            Yii::log('item_get_response not exists:'.$num_iid, 'error', 'system.fail');
            return NULL;
        }
    }
    
    //商品后端id和判断是否是区域销售
    private function _connectTmall_BO_ID_Reginal_Sales($_sessionkey,$item_id,$sku_id){
    	$_taobaoConnectBO_ID= new TaobaoConnector();
    	$_taobaoConnectBO_ID->__url=Yii::app()->params['taobao_api']['url'] ;
    	$_taobaoConnectBO_ID->__appkey= Yii::app()->params['taobao_api']['appkey'] ;
    	$_taobaoConnectBO_ID->__appsecret= Yii::app()->params['taobao_api']['appsecret'] ;
    	$_taobaoConnectBO_ID->__method= Yii::app()->params['taobao_api']['methods']['BO_ID_method'] ;
    	$_items= $_taobaoConnectBO_ID->connectTaobaoBO_ID($_sessionkey,$item_id,$sku_id);
    	$result = array('bo_id'=>null,'reginal_sales'=>0);
    	if (array_key_exists('error_response',$_items)){
    		Yii::log('Caught exception: ' . serialize($_items), 'error', 'system.fail');
    		return null;
    	}
    	if (array_key_exists('scitem_map_query_response',$_items)){
    		if(!empty($_items['scitem_map_query_response']['sc_item_maps'])){
    			$result['bo_id'] = $_items['scitem_map_query_response']['sc_item_maps']['sc_item_map'][0]['rel_item_id'];
    			if(!empty($result['bo_id'])){
    				$result['reginal_sales'] = $this->_connectTmall_Reginal_Sales($_sessionkey, $result['bo_id']);
    			}
    			return $result;
    		}
    		return null;
    	}else{
    		Yii::log('scitem_map_query_response not exists:'.$sku_id, 'error', 'system.fail');
    		return null;
    	}
    }
    
    
    //判断是否是
    private function _connectTmall_Reginal_Sales($_sessionkey,$bo_id){
    	$_taobaoConnectBO_ID= new TaobaoConnector();
    	$_taobaoConnectBO_ID->__url=Yii::app()->params['taobao_api']['url'] ;
    	$_taobaoConnectBO_ID->__appkey= Yii::app()->params['taobao_api']['appkey'] ;
    	$_taobaoConnectBO_ID->__appsecret= Yii::app()->params['taobao_api']['appsecret'] ;
    	$_taobaoConnectBO_ID->__method= Yii::app()->params['taobao_api']['methods']['Reginal_Sales_method'] ;
    	$_items= $_taobaoConnectBO_ID->connectTaobaoReginal_Sales($_sessionkey,$bo_id);
    	$reginal_sales = 0;
    	if (array_key_exists('error_response',$_items)){
    		Yii::log('Caught exception: ' . serialize($bo_id), 'error', 'system.fail');
    		return $reginal_sales;
    	}
    	if (array_key_exists('scitem_get_response',$_items)){
    		if(!empty($_items['scitem_get_response'])){
    			$reginal_sales = $_items['scitem_get_response']['sc_item']['is_area_sale'];
    		}
    		return $reginal_sales;
    	}else{
    		Yii::log('scitem_get_response not exists:'.$bo_id, 'error', 'system.fail');
    		return $reginal_sales;
    	}
    }
}

