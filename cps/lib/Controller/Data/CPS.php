<?php
namespace cps;

class Controller_Data_CPS extends \Controller_Data {
    public $debug = false;
    /* key difference */
    function setSource($model,$table=null){
        if(!$table)$table=$model->table;
        if ($t=$this->api->getConfig("cps/map/$table", null)){
            $table = $t;
        }
        if(@!$this->api->cp[$table] || $this->profile){
            if ($this->profile){
                $profile = "_Profile";
            } else {
                $profile = "";
            }
            $this->api->cp[$table] = $this->add("cps/Controller_CPS".$profile, array("debug" => $this->debug))
                ->connect($table);
        }
        parent::setSource($model,array(
            'conditions'=>array(),
            'cps'=>$this->api->cp[$table]
        ));
        $self = $this;
        $model->addMethod("_get", function($o,$k) use ($self,$model){
            return $self->_get($model, $k);
        });
        $model->addMethod("_set", function($o,$k,$v) use ($self,$model){
            return $self->_set($model, $k, $v);
        });
        $model->addMethod("_append", function($o,$k,$v) use ($self,$model){
            return $self->_append($model, $k, $v);
        });
    }
    function save($model,$id=null){
        $data=array();
        foreach($model->elements as $name=>$f)if($f instanceof \Field){
            if(!$f->editable() && !$f->system())continue;
            if(!isset($model->dirty[$name]) && $f->defaultValue()===null)continue;

            $value=$f->get();

            if($f->type()=='boolean' && is_bool($value)) {
                $value=(bool)$value;
            }
            if($f->type()=='int'){
                $value=(int)$value;
            }
            if($f->type()=='money' || $f->type()=='float'){
                $value=(float)$value;
            }

            $data[$name]=$value;

        }
        if (!$data[$model->id_field]){
            unset($data[$model->id_field]);
        }
        $id=$this->doSave($model, $data);
        return $id;
    }
    function doSave($model, $data){
        if($model->loaded()){
            $this->cps($model)->update($model, $data);
            return $model->id;
        }
        $this->cps($model)->insert($model, $data);
        $model->data=$data;  // will grab defaults here
        $model->dirty=array();
        
        return $model->id;
    }
    function delete($model,$id){
        $model->_get("cps")->delete($model, $id);
    }
    function deleteAll($model){
        $model->_get("cps")->deleteAll($model);
    }
    function getRows($model){
        throw $this->exception("Todo");
    }
    function getBy($model,$field,$cond,$value){
        throw $this->exception("Todo");
    }
    /*
     * Various loading methods
     * */
    function tryLoad($model,$id){
        $this->tryLoadBy($model,$model->id_field,$id);
    }
    function loadBy($model,$field,$cond,$value){
        $this->tryLoadBy($model,$field,$cond,$value);
        if(!$model->loaded())throw $this->exception('Record not found')
            ->addMoreInfo('id',$id);
    }
    function tryLoadAny($model){
        $r = $this->cps($model)->rewind($model);
        if ($r){
            list($ptr, $current) = $r;
            $this->loadFromIterator($model, $current);
        }
        return $this;
    }

    /** Create a new cursor and load model with the first entry */
    function rewind($model){
        $r = $this->cps($model)->rewind($model);
        if ($r){
            list($this->iter_ptr, $current) = $r;
            $this->loadFromIterator($model, $current);
        } else {
            $model->unload();
        }
        return $this;
    }

    /** Provided that rewind was called before, load next data entry */
    function next($model){
        $this->loadFromIterator($model, $this->cps($model)->next($model, $this->iter_ptr));
        return $this;
    }
    function loadFromIterator($model, $iterator){
        if (!$iterator){
            $model->unload();
            return false;
        }
        $model->_set("iterator", $iterator);
        foreach ($model->elements as $k=>$e){
            if ($e instanceof \Field){
                if ($xpath = $e->setterGetter("xpath")){
                    $a=$iterator->xpath($xpath);
                    if ($a){
                        $model->data[$e->short_name] = (string)array_shift($a);
                    } else {
                        $model->data[$e->short_name] = null;
                    }
                } else {
                    if (isset($iterator->{$e->short_name})){
                        $model->data[$e->short_name] = (string)$iterator->{$e->short_name};
                    } else {
                        $model->data[$e->short_name] = null;
                    }
                }
            }
        }
        $model->id=(string)$model->data[$model->id_field]?:null;
        return $model->id;
    }
    function cps($model){
        return $model->_get("cps");
    }
    function setLimit($model, $limit, $offset=null){
        $this->_set($model, "limit", array($limit, $offset));
    }
    function count($model){
        return $this->cps($model)->count($model);
    }
    /* generic stuff */
    /** Implements access to our private storage inside model */
    public function _get($model,$key){
        return $model->_table[$this->short_name][$key];
    }
    public function _set($model,$key,$val){
        $model->_table[$this->short_name][$key]=$val;
    }
    public function _append($model,$key,$val,$sep=" "){
        $v = $model->_get($key);
        $val = ($v?$v.$sep:"") . $val;
        $model->_set($key,$val);
    }
    function load($model,$id){
        $this->tryLoadBy($model,$model->id_field,$id);
        if(!$model->loaded())throw $this->exception('Record not found')
            ->addMoreInfo('id',$id);
    }
    function tryLoadBy($model,$field,$cond=undefined,$value=undefined){

        $condition_freeze = $model->_table[$this->short_name]['conditions'];

        $this->addCondition($model,$field,$cond,$value);

        $this->tryLoadAny($model);

        $model->_table[$this->short_name]['conditions']=$condition_freeze;
        return $model->id;

    }
    function addCondition($model,$field,$cond=undefined,$value=undefined){
        if ($value == undefined){
            $value = $cond;
            $cond = null;
        }
        if ($f=$model->hasElement($field)) {
            if($f->type()=='boolean' && is_bool($value)) {
                $value=(bool)$value;
            }
            if($f->type()=='int'){
                $value=(int)$value;
            }
            if($f->type()=='money' || $f->type()=='float'){
                $value=(float)$value;
            }

            $f->defaultValue($value)->system(true);
        }else{
            if($field[0]!='$'){
                throw $this->exception('Condition on undefined field. Does not '.
                    'look like expression either')
                    ->addMoreInfo('model',$model)
                    ->addMoreInfo('field',$field);
            }
        }
        if (!isset($model->_table[$this->short_name]['conditions'][$field])){
            $model->_table[$this->short_name]['conditions'][$field] = array();
        }
        if ($cond){
            $model->_table[$this->short_name]['conditions'][$field][]=array($value,$cond);
        } else {
            $model->_table[$this->short_name]['conditions'][$field][]=$value;
        }
    }
    function getNode($model, $field){
        return $model->_get("cps")->getNode($model, $field);
    }
}
