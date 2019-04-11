<?php
namespace App\Models;

use App\FrontFormat;
use App\Models\DriversModel;
use App\Models\GoodsModel;
use App\Models\VPromotionsModel;
use App\Models\CdekLogModel;
use App\Models\OnlinepayLogModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;


class OffersModel extends FrontFormat
{
    protected $table = 'art_shop_zakazy';
    public $timestamps = false;

    protected $_historyLog_tableName = 'art_shop_log_n';
    protected $_historyLog_foreignKey = 'rec_id';
    protected $_historyLog_fieldName = 'fldname';
    protected $_historyLog_oldValue = 'old';
    protected $_historyLog_newValue = 'new';
    protected $_historyLog_createdField = 'kogda';
    protected $_sendMesssages_name = 'offers';

    protected $guarded = ['id', 'kogda', 'nomer'];

    protected $attributes = [
        'gde' => 1,
        'dosttime' => 'с 20 до 25',
        'vyvozmesto' => 'Волгоградский',
        'vyvoztime' => 'с 19 до 21',
        'newformat' => 2,
        'ubonus' => 0,
        'tk' => ''
    ];

    const YMAP_API_KEY = 'AG-xyEsBAAAAcv8FRAIAts0E5KueHz0vwMcHUvbW1DQYr6UAAAAAAAAAAAAOKstbFg0jCSl4PXiTsAduWjzfEw==';
    const OFFERS_TABLENAME = 'art_shop_zakazy';
    const PAYTYPE_NAL = 1;
    const DELIVERTYPE_DELIVERY = 1;
    const PAYTYPE_ONLINE = 3;
    const DEFAULT_COLOR = '#0095b6';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setAppends(['DT_RowId', 'deliveryAddress', 'deliveryDate']);
    }

    public function warranty()
    {
      return  $this->belongsTo('App\Models\WarrantyModel');
    }

    public function goods()
    {
        return $this->belongsToMany('App\Models\GoodsModel', 'offers__goods', 'offer_id', 'good_id')
            ->withPivot('good_count', 'price', 'good_id', 'price_first')
            ->select('id', 'title', 'mass')
            ->orderBy('kogda', 'asc');
    }

    public function cdekLog()
    {
       return $this->hasMany('App\Models\CdekLogModel','offer_id','id');
    }

    public function onlinepayLog()
    {
        return  $this->hasOne('App\Models\OnlinepayLogModel','offer_id', 'id' );
    }

    public function getDeliveryAddressAttribute()
    {
        if ($this->deliveryType == 2) {
            return $this->vyvozmesto;
        }
        $addr = '';
        if (!empty($this->gorod)) {
            $addr .= $this->gorod;
        }
        if (!empty($this->ulica)) {
            $addr .= empty($addr) ? $this->ulica : ', ' . $this->ulica;
        } else {
            return $addr;
        }
        if (!empty($this->dom)) {
            $addr .= ', д. ' . $this->dom;
        }
        if (!empty($this->korp)) {
            $addr .= ', корп. ' . $this->korp;
        }
        if (!empty($this->stroen)) {
            $addr .= ', стр. ' . $this->stroen;
        }
        if (!empty($this->kv)) {
            $addr .= ', кв. ' . $this->kv;
        }

        return $addr;
    }

    public function getDeliveryDateAttribute()
    {
        return $this->dostdata . ' ' . $this->dosttime;
    }

    public function driver()
    {
        return $this->belongsToMany('App\Models\DriversModel', 'relations__main', 'from_id', 'to_id')->orderBy('timestamp', 'ASC');
    }

    public function log()
    {
        $logSettings = $this->getHistoryLog_settings();
        $logInstanceQuery = parent::log();
        // TODO: эту инфа нужная - необходимо вернуть - и как-то оформить
        $logInstanceQuery->where($logSettings['fieldName'], '<>', 'tovar_info');
        return $logInstanceQuery;
    }

    public function deliveryPrice($force_goods = null, $force_mkad_distance = null)
    {
        $data = [];                 // массив с возвращаемыми результатами
        $calc_price = 0;            // рассчетная стоимость доставки
        $calc_price_mkad = 0;       // наценка на расстояние за мкад для доставки по "мо"
        $full_mass = 0;             // вес заказа
        $freeDelivery_goods = [];   // товары с бесплатной доставкой

        $goods = unserialize($this->tovar_info);
        if (!is_null($force_goods)) {
            $goods = $force_goods;
        }
        $mkad_distance = $this->mkad_distance;
        if (!is_null($force_mkad_distance)) {
            $mkad_distance = $force_mkad_distance;
        }

        foreach ($goods AS $key => $item) {
            $full_mass += GoodsModel::getMass($item[0]) * $item[2];
            if (GoodsModel::find($item[0])->isDeliveryFree()) {
                $freeDelivery_goods[] = $item[0];
            }
        }

        $_type = mb_strtolower(mb_substr($this->gorod, 0, 2));
        switch ($_type) {
            case 'мо':
                $calc_price = ($mkad_distance > 0 ? 450 : 350);
                if ($mkad_distance > 0) {
                    $calc_price_mkad = ($mkad_distance > 10) ? (($mkad_distance > 20) ? 600 : 400) : 200;
                }
                break;
            default:
                $calc_price = ($mkad_distance > 0 ? 450 : 350);
                break;
        }

        // наценки за вес
        if ($full_mass >= 4) {
            $calc_price = ($full_mass < 25 ? 450 : 700);
        }

        $data['moscow'] = $calc_price;
        $data['mkad'] = $calc_price_mkad;
        $data['price'] = $data['moscow'] + $data['mkad'];
        if (count($freeDelivery_goods) > 0) {
            $data['freeGoods'] = $freeDelivery_goods;
        }
        return $data;
    }

    public function _addGoods($good)
    {
        $attributes['offer_id'] = $this->id;
        $attributes['good_id'] = $good['goods']->id;
        $attributes['good_count'] = $good['good_count'];
        $attributes['price'] = $good['goods']->price;
        $attributes['price_first'] = $good['price_first'];
        $attributes['kogda'] = NOW();

        $res = $this->goods()->updateExistingPivot($good['goods']->id, $attributes);
        if (!$res)
            $this->goods()->attach($good['goods']->id, $attributes);
    }

    public function _deleteGoods($id)
    {
        $this->goods()->detach($id);
    }

    public  function getColor() {
        $color = $this->driver()->last()->color;
        if (!empty($color))
            return  $color;
        return false;
    }


    public function getCardnumber() {
        if((int) $this->payType === self::PAYTYPE_ONLINE && in_array($this->flgoplata, [1, 4])) {
            $result = $this->onlinepayLog()->first()->toArray();

            if(empty($result)) {
                $message = 'Не удалось найти запись об оплате заказа ' . $this->id;
               // LOGS::addMsg(self::LOG_SCOPE, $message, LOGS::ERROR);       ///!!!!
                throw new InvalidArgumentException($message);
            }
            return '****' . substr($result['cardnumber'], -4, 4);
        }
        return '';
    }


    public function isCashOnDeliveryOrder() {
        if ($this->payType == self::PAYTYPE_NAL && $this->deliveryType == self::DELIVERTYPE_DELIVERY &&
            ($this->tk != '' || $this->cdek_pvz != '')) {
            return true;
        }
        return false;
    }


    public function getCashOnDeliveryDate()
    {
        if (!$this->isCashOnDeliveryOrder()) {
            return false;
        }
        $logRow_cashDate = $this->cdekLog()->where('new_value','>','0')->orderBy('date','DESC')->first();
        if ($logRow_cashDate) {
            return date('d.m.Y H:i', strtotime($logRow_cashDate->date));
        }
        else {
            throw new Exception('Не удалось определить дату совершения наложенного платежа для заказа ' . $this->id);
        }

        return false;
    }

    public function getInfo(){
        if($this->_currentOfferId){
            return $this->_offers[$this->_currentOfferId];
        }
        else{
            return $this->_offers;
        }
    }




    protected function _getComputers($day ){
        return $this->_getCounters($this->getComputers($day),1);
    }

    protected function _get1C_fail(){
        return $this->_getCounters($this->get1C_fail(),1);
    }

    protected function _getDelivery($day){
        return $this->_getCounters($this->getDelivery($day),92, 'DAY');
    }

    protected function _getDeliveryDone(){
        return $this->_getCounters($this->getDeliveryDone(),186, 'DAY');
    }

    protected function _getDeliveryInProcess($day){
        return $this->_getCounters($this->getDeliveryInProcess($day),92, 'DAY');
    }

    protected function _getDeliveryTK($state){
        return $this->_getCounters($this->getDeliveryTK($state),92, 'DAY');
    }

    protected function _getReserveExceeded(){
        return $this->_getCounters($this->getReserveExceeded(),1);
    }

    protected function _getReturned(){
        return $this->_getCounters($this->getReturned(),1);
    }

    protected function _getFresh(){
        return $this->_getCounters($this->getFresh(),1);
    }

    protected function _getOnline(){
        return $this->_getCounters($this->getOnline(),31, 'DAY');
    }



    public function scopeGetOnline($query){
        $query->where('payType',3)->whereIn('status',[0,1,4,5,8,9])->whereIn('flgoplata',[0,3]);

        return $query;
    }

    public function scopeGetFresh($query){
       $query->where(function($sql){
           $sql->whereIn('status',[0,9],'or')->orWhere('manager','');
       });

        return $query;
    }


    public function scopeGetReturned($query){
       $query->where([
           ['status',4],
           ['fizyurType', '<>',2],
           ['payType', '<>',2]
       ])->where(function($sql){
           $sql->orWhere('rnomer','like','%Возврат:%');
           $sql->orWhere('prim','like','%Возврат:%');
           $sql->orWhere('rkp','like','%Возврат:%');
           $sql->orWhere('dostinfo','like','%Возврат:%');
       });
        return $query;
    }


    public function scopeGetReserveExceeded($query){
        $query->where([
            ['status',6],
            ['itog','>',9999],
            ['prichina','']
        ]);
        return $query;
    }


    public function scopeGetDeliveryTK($query,$state = 'process'){
        switch($state){
            case 'done':
                $query->where('status',5)->where([
                    ['flgoplata','<>',3],
                    ['tk','<>','СДЭК'],
                    ['tk','<>','']
                ])->where(function($sql1){
                    $sql1->where('statuskomp','')->orWhere(function ($sql2){
                        $sql2->where('statuskomp','NOT LIKE','%Ожидание%');
                        $sql2->where('statuskomp','NOT LIKE','%Подготовка%');
                        $sql2->where('statuskomp','NOT LIKE','%Брак%');
                        $sql2->where('statuskomp','NOT LIKE','%Сборка%');
                    });
                });
                break;
            case 'doneCDEK':
                $query->where('status',5)->orWhere(function($sql1){
                    $sql1->where( function($sql2) {
                        $sql2->whereIn('flgoplata',[1,4])->where('tk','СДЭК');
                    })->where( function($sql3){
                        $sql3->where([
                            ['flgoplata',0],
                            ['tk','!=',''],
                            ['payType',1]
                        ]);
                    });
                })->where(function($sql1){
                    $sql1->where('statuskomp','')->orWhere(function ($sql2){
                        $sql2->where('statuskomp','NOT LIKE','%Ожидание%');
                        $sql2->where('statuskomp','NOT LIKE','%Подготовка%');
                        $sql2->where('statuskomp','NOT LIKE','%Брак%');
                        $sql2->where('statuskomp','NOT LIKE','%Сборка%');
                    });
                });
                break;
            case 'processCDEK':
                $query->where('status',8)->where([
                    ['flgoplata','<>',3],
                    ['tk','СДЭК']
                ])->orWhere(function($sql1){
                    $sql1->where('status',5)->where(function($sql2){
                       $sql2->orWhere('statuskomp','LIKE','%Ожидание%');
                       $sql2->orWhere('statuskomp','LIKE','%Подготовка%');
                       $sql2->orWhere('statuskomp','LIKE','%Брак%');
                       $sql2->orWhere('statuskomp','LIKE','%Сборка%');
                    })->orWhere(function($sql3){
                        $sql3->where('status',5);
                        $sql3->where('flgoplata',5);
                    });

                });
                break;
            case 'process':
            default:

            $query->where([
                ['status',8],
                ['flgoplata', '<>', 3],
                ['tk','<>','СДЭК'],
                ['tk','<>','']
            ]);
            $query->orWhere(function($sql1){
                $sql1->where('status',5)->where(function($sql2){
                    $sql2->orWhere('statuskomp','LIKE','%Ожидание%');
                    $sql2->orWhere('statuskomp','LIKE','%Подготовка%');
                    $sql2->orWhere('statuskomp','LIKE','%Брак%');
                    $sql2->orWhere('statuskomp','LIKE','%Сборка%');
                })->
               orWhere(function($sql3){
                    $sql3->where('status',5);
                    $sql3->where('flgoplata',5);
                });
            });
             break;
        }
        $query->where('deliveryType','1')->where(function($sql4){
            $sql4->whereRaw('LENGTH(`gorod`)>0');
        });
        return $query;
    }

    public function scopeGetDeliveryInProcess($query,$day = 'today'){
        $query->where('status',4);
        switch($day){
            case 'afterTomorrow':
                $query->whereRaw('dostdata = (CURDATE() + INTERVAL 2 DAY)');
                break;
            case 'tomorrow':
                $query->whereRaw('dostdata = (CURDATE() + INTERVAL 1 DAY)');
                break;
            case 'today':
            default:
            $query->whereRaw('dostdata = CURDATE()');
                break;
        }
       $query->where('deliveryType','=','1');
        return $query;
    }

    public function scopeGetDeliveryDone($query){
        $query->where('status',5)->where('deliveryType',1)->where(function ($sql){
            $sql->where('statuskomp','')->orWhere(function ($sql1){
                $sql1->where([
                    ['statuskomp','NOT LIKE','%Ожидание%'],
                    ['statuskomp','NOT LIKE','%Подготовка%'],
                    ['statuskomp','NOT LIKE','%Брак%'],
                    ['statuskomp','NOT LIKE','%Сборка%']
                ]);
            });
        });
        $query->where('flgoplata','<>', 3);
    }

    public function scopeGetDelivery($query, $day = 'today'){

        switch($day){
            case 'afterTomorrow':
                $query->whereIn('status', [1,5,8,6]);
                $query->whereRaw('dostdata = (CURDATE() + INTERVAL 2 DAY)');
                break;
            case 'tomorrow':
                $query->whereIn('status', [1,5,8,6]);
                $query->whereRaw('dostdata =(CURDATE() + INTERVAL 1 DAY)');
                break;
            case 'today':
            default:
            $query->whereIn('status', [5,8,6]);
            $query->whereRaw('dostdata > 0000-00-00')->whereRaw('dostdata <= CURDATE()');
                break;
        }
        $query->where('deliveryType','=',1)->where('flgoplata','<>',3);
        return $query;
    }

    public function scopeGetComputers($query, $state = 'wait'){

        $query->where(function ($sql) {
            $sql->whereIn('status', [1, 5, 8])->orWhere(function($q) {
            $q->where('status', 6)->where('prichina', '');
        });
        });

        switch($state){
            case 'done':
                $query->orWhere('statuskomp', '=', 'Готов ');
                break;
            case 'process':
                $query->orWhere('statuskomp', '=', 'Сборка');
                break;
            case 'defect':
                $query->orWhere('statuskomp', '=', 'Брак');
                break;
            case 'wait':
            default:
            $query->where(function($q){
                $q->orWhere('statuskomp','like','%Ожидание%')
                ->orWhere('statuskomp','like','%Подготовка%');
            }) ;
            break;
        }

        return $query;
    }

    public function scopeGet1C_fail($query) {
        $query->where([['status', '=', 1],
            ['fizyurType', '!=', 2],
            ['payType','!=',2]]);
        return $query;
    }

    public function scopeGetComputersWait($query,$day = 'today'){
        $query->whereIn('status',[1,6,5,8])->where(function($sql){
            $sql->orWhere('statuskomp', 'LIKE', '%Ожидание%');
            $sql->orWhere('statuskomp', 'LIKE', '%Подготовка%');
            $sql->orWhere('statuskomp', 'LIKE', '%Сборка%');
            $sql->orWhere('statuskomp', 'LIKE', '%Брак%');
        });
        switch($day){
            case 'afterTomorrow':
                $dostdata = ' + INTERVAL 2 DAY';
                break;
            case 'tomorrow':
                $dostdata = ' + INTERVAL 1 DAY';
                break;
            case 'today':
            default:
                break;
        }
        $query->where(function($sql2){})
            ->orWhere([['dostdata','(CURDATE()'.$dostdata.')'],
                         ['deliveryType',2]]);
        return $query;
    }


    protected function _getCounters($query, $periodValue = FALSE, $periodType = 'MONTH'){

        $counters = array(
            'departments'	=> array(
                '1'	=> 0,
                '2'	=> 0
            ),
            'managers'		=> array(),
            'manager'		=> 0,
            'full'			=> 0
        );
           $query->select('manager', 'payType', 'fizyurType', DB::raw('COUNT(*) as offersCount'))
                ->whereRaw('kogda > (CURDATE() - INTERVAL 1 YEAR)')
                ->where('hide_in_logistic', NULL)
                ->whereRaw( 'kogda > (CURDATE() - INTERVAL ' . $periodValue . ' ' . $periodType . ')')
            ->groupBy('manager', 'payType', 'fizyurType');
        $res = $query;
        $queryCount = $res->get()->toArray();
        if($queryCount){
            foreach($queryCount as $row){
                $counters['full'] += $row['offersCount'];
                $departmentFlag = $row['fizyurType'].'_'.$row['payType'];
                switch($departmentFlag){
                    case '_':
                    case '_3':
                    case '1_3':
                       $counters['departments'][1] += $row['offersCount'];
                        break;
                    case '_':
                    case '_0':
                    case '_1':
                    case '0_':
                    case '0_0':
                    case '0_1':
                    case '1_':
                    case '1_0':
                    case '1_1':
                        $counters['departments'][1] += $row['offersCount'];
                        break;
                    case '_2':
                    case '0_2':
                    case '1_2':
                    case '2_':
                    case '2_0':
                    case '2_1':
                    case '2_2':
                        $counters['departments'][2] += $row['offersCount'];
                        break;
                }
                if(empty($counters['managers'][$row['manager']])){
                    $counters['managers'][$row['manager']] = 0;
                }
                $counters['managers'][$row['manager']] += $row['offersCount'];
            }
        } else $this->_error('Error Sql! $where: '. $query->toSql(), __LINE__);
        return $counters;
    }
}
