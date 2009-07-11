<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Retrieves and manipulates current status of hosts (and services?)
 */
class Data_Model extends Model
{

    private $XML   = array();
    public  $DS    = array();
    public  $MACRO = array();
    private $RRD   = array();
    public  $STRUCT  = array();
    public  $TIMERANGE = array();	
    /*
    * 
    *
    */
    public function __construct(){
		$this->config = new Config_Model();	
		$this->config->read_config();
    	#print Kohana::debug($this->config->views);
    }


    /*
    * 
    *
    */
    public function getHosts() {
    	$hosts = array();
		$conf = $this->config->conf;
		$i = 0;
       	if (is_dir($conf['rrdbase'])) {
           	if ($dh = opendir($conf['rrdbase'])) {
               	while (($file = readdir($dh)) !== false) {
                   	if ($file == "." || $file == "..")
                       	continue;
                   	$stat = stat($conf['rrdbase'] . "/" . $file);
                   	$age = (time() - $stat['mtime']);
                   	$hosts[$i]['name'] = $file;
                   	if ($age < $conf['max_age']) {
                       	$hosts[$i]['state'] = 'active';
                   	} else {
                       	$hosts[$i]['state'] = 'inactive';
                   	}
				$i++;
               	}
               	closedir($dh);
           	} else {
			throw new Kohana_User_Exception('Perfdata Dir', "Can not open $path");
           	}
       	}
        if(sizeof($hosts)>0){
            natsort($hosts);
        }else{
		throw new Kohana_Exception('common.perfdata-dir-empty', $conf['rrdbase'] );
	}
        return $hosts;
    }


    /*
    * 
    *
    */
    function getServices($hostname) {
        $services = array ();
        $host     = array();
		$conf     = $this->config->conf;
        $i        = 0;
        $path     = $conf['rrdbase'] . $hostname;
        if (is_dir($path)) {
            if ($dh = opendir($path)) {
                while ( ($file = readdir($dh) ) !== false) {
                    $NAGIOS_SERVICEDESC = "";
                    if ($file == "." || $file == "..")
                        continue;
                    if (!preg_match("/(.*)\.xml$/", $file, $servicedesc))
                        continue;
                    $fullpath = $path . "/" . $file;
                    $stat = stat("$fullpath");
                    $age = (time() - $stat['mtime']);
		    		$xml = $this->readXML($hostname, $servicedesc[1]);
	
		    		$state = "active";	
                    if ($age > $conf['max_age']) { # 6Stunden
		        		$state = "inactive";
		    		}
		
                    if($servicedesc[1] == "_HOST_"){
                        $host[0]['name']             = "_HOST_";
                        $host[0]['hostname']         = (string) $xml->NAGIOS_HOSTNAME;
                        $host[0]['state']            = $state;
                        $host[0]['servicedesc']      = "Host Perfdata";
                    }else{
                        $services[$i]['name']        = $servicedesc[1];
                        $services[$i]['state']       = $state;
                        $services[$i]['hostname']    = (string) $xml->NAGIOS_DISP_HOSTNAME;
                        $services[$i]['servicedesc'] = (string) $xml->NAGIOS_DISP_SERVICEDESC;
                    }
				$i++;
                }
                closedir($dh);
            }
        } else {
	    throw new Kohana_User_Exception('Perfdata Dir', "Can not open $path");
        }
        if( is_array($host) ){
			sort($services);
            array_unshift($services, $host[0]);
        }
        return $services;
    }

    /*
    * 
    *
    */
    public function getFirstService($hostname) {
        $conf = $this->config->conf;
        $services = $this->getServices($hostname);
        foreach ($services as $srv) {
            if ($srv['state'] == "active" ) {
                break;
            }
        }
        return $srv['name'];
    }

    /*
    * 
    *
    */
    public function getFirstHost() {
        $conf = $this->config->conf;
        $hosts = $this->getHosts();
        foreach ($hosts as $host) {
            if ($host['state'] == "active" ) {
                break;
            }
        }
        return $host['name'];
    }

    /*
    * 
    *
    */
    public function readXML ($hostname, $servicedesc){
	$conf        = $this->config->conf;
	$this->XML   = array();
	$this->MACRO = array();
	$this->DS    = array();
	$xml	     = array();
	$xmlfile     = $conf['rrdbase'].$hostname."/".$servicedesc.".xml";
	if (file_exists($xmlfile)) {
    	$xml = simplexml_load_file($xmlfile);
	    foreach ( $xml as $key=>$val ){
			if(preg_match('/^NAGIOS_(.*)$/', $key, $match)){
		    	#print $match[1]." => ".$val."\n";
		    	$key = $match[1];
		    	$this->MACRO[$key] = (string) $val;
			}
	    }
	    $i=0;
	    foreach ( $xml->DATASOURCE as $datasource ){
	        foreach ( $datasource  as $key=>$val){
		    	#print "$key => $val\n";
		    	#$$key[$i] = (string) $val;
	            $this->DS[$i][$key] = (string) $val;
	        }
	        $i++; 
	    } 
	    return $xml;
	}
	return false;
    }

    /*
    * 
    *
    */
    public function buildDataStruct ($host = FALSE, $service = FALSE, $view = "", $source = "", $single = FALSE){
		if($host === false && $service === false){
	    	return false;
		}
		$conf        = $this->config->conf;
		$xml         = $this->readXML($host,$service);
    	$this->includeTemplate($this->DS[0]['TEMPLATE']);
		#print Kohana::debug($this->TIMERANGE);
		if( $view == "" ){
			$v = 0;
	    	foreach($this->config->views as $view_key=>$view_val){
				$i=0;
	        	foreach( $this->RRD['def'] as $key=>$val){
		    		if($source != "" && $source != $key ){
						continue;
					}
	            	$tmp_struct = array();
	            	#$tmp_struct['def']           = $this->RRD['def'][$key];
	            	#$tmp_struct['opt']           = $this->RRD['opt'][$key];
		    		$tmp_struct['LEVEL']         = $i;
		    		$tmp_struct['VIEW']          = $view_key;
		   	 		$tmp_struct['SOURCE']        = $key;
		    		$tmp_struct['RRD_CALL']      = $this->TIMERANGE[$v]['cmd'] . " " . $this->RRD['opt'][$key] . " " . $this->RRD['def'][$key];
	            	if(array_key_exists('ds_name',$this->RRD) ){
	     	       		$tmp_struct['ds_name']   = $this->RRD['ds_name'][$key];
		    		}else{
	     	       		$tmp_struct['ds_name']   = $this->DS[$i]['NAME'];
		    		}
	            	$tmp_struct['TIMERANGE']     = $this->TIMERANGE[$v];
	            	$tmp_struct['DS']            = $this->DS[$i];
	            	$tmp_struct['MACRO']         = $this->MACRO;
	            	$this->addToDataStruct($tmp_struct);
	            	$i++;
	        	}
			$v++;
	    	}
		}else{
	    	$view = intval($view);
	    	$i=0;
	    	foreach( $this->RRD['def'] as $key=>$val){
				if( $source != "" && $source != $key ){
		    		continue;
				}
	        	$tmp_struct = array();
				$tmp_struct['LEVEL']         = $i;
				$tmp_struct['VIEW']          = $view;
				$tmp_struct['SOURCE']        = $key;
	        	#$tmp_struct['RRD_CALL']      = $this->TIMERANGE[$view]['cmd'] . " ". $this->RRD['opt'][$key] . " " . $this->RRD['def'][$key];
	        	$tmp_struct['RRD_CALL']      = $this->TIMERANGE['cmd'] . " ". $this->RRD['opt'][$key] . " " . $this->RRD['def'][$key];
	        	if(array_key_exists('ds_name',$this->RRD) ){
	     	    	$tmp_struct['ds_name']   = $this->RRD['ds_name'][$key];
				}else{
	     	    	$tmp_struct['ds_name']   = $this->DS[$i]['NAME'];
				}
	        	$tmp_struct['TIMERANGE']     = $this->TIMERANGE[$view];
	        	$tmp_struct['DS']      = $this->DS[$i];
	        	$tmp_struct['MACRO']   = $this->MACRO;
	        	$this->addToDataStruct($tmp_struct);
	        	$i++;
            }
		}
	#print Kohana::debug($this->STRUCT);
    }

    /*
    * 
    *
    */
    private function addToDataStruct ($data=FALSE) {
	if($data === FALSE)
		return FALSE;

	array_push($this->STRUCT, $data);
    } 

    /*
    * 
    *
    */
    private function includeTemplate($template=FALSE){
		if($template===FALSE){
	    	return FALSE;
		}
		$this->RRD = array();
		$template_file = $this->findTemplate( $template );
		$hostname      = $this->MACRO['HOSTNAME'];
		$servicedesc   = $this->MACRO['SERVICEDESC'];
		$rrdfile = $this->MACRO['RRDFILE'];
		$def     = FALSE;
		$opt     = FALSE;
		$ds_name = FALSE;
		/*
		* 0.4.x Template compatibility 
		*/
		foreach($this->DS as $key=>$val ){
			$key++;
            foreach(array_keys($val) as $tag){
	        	${$tag}[$key] = $val[$tag];
            }
        }
		ob_start();
		include($template_file);
		ob_end_clean();
		if( $def != FALSE ){
	    	$this->RRD['def'] = $def;
        }else{
            throw new Kohana_User_Exception('Template Error', "Template $template_file does not provide the array \$def");
		}
		if( $opt != FALSE ){
	    	$this->RRD['opt'] = $opt;
        }else{
            throw new Kohana_User_Exception('Template Error', "Template $template_file does not provide the array \$def");
		}
		if( $ds_name != FALSE ){
	    	$this->RRD['ds_name'] = $ds_name;
        }
		return TRUE;		
    }
	
    /*
    * 
    *
    */
    public function findTemplate($template){
	$conf = $this->config->conf;
        $r_template = $this->findRecursiveTemplate($template,"templates");
        $r_template_dist = $this->findRecursiveTemplate($template,"templates.dist");

        if (is_readable($conf['template_dir'].'/templates/' . $template . '.php')) {
            $template_file = $conf['template_dir'].'/templates/' . $template . '.php';
        }elseif (is_readable($conf['template_dir'].'/templates.dist/' . $template . '.php')) {
            $template_file = $conf['template_dir'].'/templates.dist/' . $template . '.php';
        }elseif($r_template != "" ){
            $template_file = $conf['template_dir'].'/templates/'. $r_template . '.php';
        }elseif($r_template_dist != "" ){
            $template_file = $conf['template_dir'].'/templates.dist/'. $r_template_dist . '.php';
        }elseif (is_readable($conf['template_dir'].'/templates/default.php')) {
            $template_file = $conf['template_dir'].'/templates/default.php';
        }else {
            $template_file = $conf['template_dir'].'/templates.dist/default.php';
        }
	return $template_file;
    }

    /*
    * 
    *
    */
    function findRecursiveTemplate($template, $dir="templates") {
	$conf = $this->config->conf;
        $template_file = "";
        $r_template = "";
        $recursive = explode("_", $template);
        if($conf['enable_recursive_template_search'] == 1){
            $i = 0;
            foreach ($recursive as $value) {
                if ($i == 0) {
                    $r_template = $value;
                } else {
                    $r_template = $r_template . '_' . $value;
                }
                if (is_readable($conf['template_dir']. '/' . $dir . '/' . $r_template . '.php')) {
                    $template_file = $r_template;
                }
                $i++;
            }
        }
        return $template_file;
    }

    public function getTimeRange($start=FALSE ,$end=FALSE ,$view=1) {
        $view=intval( pnp::clean($view) );
        if($view >= sizeof($this->config->views)){
            $view = 1;
        }

        if(!$end){
            $end = time();
        }elseif(!is_numeric($end)){
            $timestamp = strtotime($end);
            if(!$timestamp){
                #$debug->doCheck('print_r',"wrong fmt $timestamp");
            }else{
                $end = $timestamp;
            }
        }else{
            $end = $end;
        }

        if(!$start){
            $start=( $end - $this->config->views[$view]['start']);
        }elseif(!is_numeric($start)){
            $timestamp = strtotime($start);
            if(!$timestamp){
                #$debug->doCheck('print_r',"wrong fmt $timestamp");
            }else{
                $start = $timestamp;
            }
        }else{
            $start = pnp::clean($start);
        }

    if($start >= $end){
		throw new Kohana_User_Exception('Wrong Timerange', "start >= end");
    }
    $timerange['start']   = $start;
    $timerange['f_start'] = date($this->config->conf['date_fmt'],$start);
    $timerange['end']     = $end;
    $timerange['f_end']   = date($this->config->conf['date_fmt'],$end);
    $timerange['cmd']     = " --start $start --end $end ";
    for ($i = 0; $i < sizeof($this->config->views); $i++) {
        $timerange[$i]['start']   = $end - $this->config->views[$i]['start'];
        $timerange[$i]['f_start'] = date($this->config->conf['date_fmt'],$end - $this->config->views[$i]['start']);
        $timerange[$i]['end']     = $end;
        $timerange[$i]['f_end']   = date($this->config->conf['date_fmt'],$end);
        $timerange[$i]['cmd']     = " --start " . ($end - $this->config->views[$i]['start']) . " --end  $end" ;
    }
    $this->TIMERANGE = $timerange;
    #print Kohana::debug($timerange);
}
    private function getGraphDimensions () {

    }
}