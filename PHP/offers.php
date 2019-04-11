<?php

define('OFFERS_TABLENAME',		'art_shop_zakazy');

/**
 * Class OFFERS
 *
 * @property string flgoplata
 * @property string payType
 * @property string itog
 * @property string dostprice
 * @property string id
 * @property string status
 * @property string nomer
 * @property string fam_inner
 * @property string normalphone
 * @property string normalphone2
 * @property string gorod
 * @property string ulica
 * @property string dom
 * @property string korp
 * @property string stroen
 * @property string kv
 * @property string cdek_status
 * @property string rnomer
 * @property string memo
 * @property string cdek_pvz
 */
class OFFERS{
    const LOG_SCOPE = 'offers';

    // Статусы хранятся также в таблице art_shop_z_status
	const STATUS_NEW = 0; // Новый
	const STATUS_CONFIRMED = 1; // Подтверждён
	const STATUS_CANCELLED = 3; // Аннулирован
	const STATUS_SHIPPED = 4; // Отгружен
	const STATUS_READY = 5; // Готов к отгрузке
	const STATUS_RESERVE_DEADLINE = 6; // Срок резерва
	const STATUS_INVOICED = 7; // Выставлен счёт
	const STATUS_PROCESSING = 8; // В обработке
	const STATUS_PHONE = 9; // Телефон
	const STATUS_ANY = -1; // Любой
	const STATUS_NONEXISTENT = -2; // Несуществующий

    const CLIENTTYPE_PERSON = 1;
    const CLIENTTYPE_COMPANY = 2;
    const PAYTYPE_NAL = 1;
    const PAYTYPE_BEZNAL = 2;
    const PAYTYPE_ONLINE = 3;
    const DELIVERTYPE_DELIVERY = 1;
    const DELIVERTYPE_PICKUP = 2;

    const NOT_PAID = 0;
    const PAID = 1;
    const PAID_PARTIALLY = 2;
    const NOT_PAID_ONLINE = 3;
    const PAID_ONLINE = 4;
    const PAID_ONLINE_PARTIALLY = 5;

    const TYPE_PHONE = 'RT(тел)';
    const TYPE_BASKET = 'RT';
    const TYPE_CALLME = 'ОЗ';
    const TYPE_QUICK = 'БЗ';
    const TYPE_TERMINAL = 'TRM';
    const TYPE_YANDEXMARKET = 'ЯМ';

	protected $_offers = array();
	protected $_currentOfferId = FALSE;
	protected $_keyForGoodsMem = 'GoodsInOffers__';
	protected $_errors = array();
	protected $_fieldInGoods = array(
				0 => 'good_id', 
				1 => 'price', 
				2 => 'good_count', 
				4 => 'action_type',
				5 => 'action_id'
			);
	const TableName = OFFERS_TABLENAME;
	const TableNameGoods = 'offers__goods';
	const TimeCacheGoods = 60;

    /**
	 * @var self|null
	 */
	protected static $_singleton = NULL;

	/**
	 * @param array $offers_ids
	 * @param bool $refreshFlag
	 *
	 * @return null|OFFERS
	 */
	protected static function _getInstance($offers_ids = array(), $refreshFlag = FALSE){
		if(is_null(self::$_singleton)){
			$className = __CLASS__;
			self::$_singleton = new $className;
		}
		if(count($offers_ids) > 0){
			if(!self::$_singleton->_loadOffers($offers_ids, $refreshFlag)){
				return NULL;
			}
		}
		return self::$_singleton;
	}
	public static function Id($offer_id = array()){
		if(!is_array($offer_id)){
			$offer_id = array($offer_id);
		}
		return self::_getInstance($offer_id);
	}
	public static function Refresh($offers_ids = array()){
		if(!is_array($offers_ids)){
			$offers_ids = array($offers_ids);
		}
		return self::_getInstance($offers_ids, 'refresh');
	}
	public static function getCounters($manager_login = FALSE){
		return self::_getInstance()->_getCounters($manager_login);
	}
	public static function getCount($manager_login = FALSE){
		return self::_getInstance()->_getCount($manager_login);
	}
	public static function Exceeded($manager_login = FALSE){
		return self::_getInstance()->_getExceeded($manager_login);
	}
	public static function ReserveExceeded($manager_login = FALSE){
		return self::_getInstance()->_getReserveExceeded($manager_login);
	}
	public static function Returned($manager_login = FALSE){
		return self::_getInstance()->_getReturned($manager_login);
	}
	public static function Online($manager_login = FALSE){
		return self::_getInstance()->_getOnline($manager_login);
	}
	public static function Baza1C_fail($manager_login = FALSE){
		return self::_getInstance()->_get1C_fail($manager_login);
	}
	public static function Fresh($manager_login = FALSE){
		return self::_getInstance()->_getFresh($manager_login);
	}
	public static function Observed($manager_login = FALSE){
		return self::_getInstance()->_getObserved($manager_login);
	}
	public static function Delivery($day = 'today'){
		return self::_getInstance()->_getDelivery($day);
	}
	public static function DeliveryDone(){
		return self::_getInstance()->_getDeliveryDone();
	}
	public static function DeliveryInProcess($day = 'today'){
		return self::_getInstance()->_getDeliveryInProcess($day);
	}
	public static function DeliveryTK($state = 'process'){
		return self::_getInstance()->_getDeliveryTK($state);
	}
	public static function Computers($state = 'wait'){
		return self::_getInstance()->_getComputers($state);
	}
	public static function ComputersWait($day = 'today'){
		return self::_getInstance()->_getComputersWait($day);
	}
	public static function UnPrinted($manager_login = FALSE){
		return self::_getInstance()->_getUnPrinted($manager_login);
	}
	public static function IdList($offers_ids = array()){
		return self::_getInstance($offers_ids);
	}
	public static function YM() {
        return self::_getInstance()->_getYM();
    }
	public static function autoSave($offerData = array()){
		$queryInsert = '
			INSERT INTO
				`'.OFFERS_TABLENAME.'`
				(`'.implode('`, `', array_keys($offerData)).'`, `hide_in_logistic`)
			VALUES
				(\''.implode('\', \'', $offerData).'\', NULL)
		';
		// заглушка
		return $queryInsert;
		// ---
		if(DB::mysqli()->query($queryInsert)){
			return DB::mysqli()->insert_id;
		}
		else{
			return FALSE;
		}
		
	}
    public static function find($searchValue, $searchByfield = 'id'){
        $offer = FALSE;
        $selectQuery = '
            SELECT
                *
            FROM
                '.OFFERS_TABLENAME.'
            WHERE
                `'.$searchByfield.'`=\''.DB::mysqli()->real_escape_string($searchValue).'\'
        ';
        $searchResult = DB::mysqli()->query($selectQuery);
        if($searchResult){
            if($searchResult->num_rows === 1){
                $offer = $searchResult->fetch_assoc();
            }
        }
        return $offer;
    }
    /*
     *  создание заказа по аналогии с корзиной на сайте - ДЛЯ ФИЗ.КЛИЕНТА !!!
     */
    public static function create($attributes, $goods, $client = [], $deliveryAddress = [], $specialComment = ''){
        $createdOffer = FALSE;
        $prisut = [];
        foreach($goods as $goodInfo){
            $prisut[] = $goodInfo[0];
        }
        $offer = [
            'zakaz'         => $attributes['type'],
            'payType'       => $attributes['payType'],
            'gde'           => 1,   // TODO: уточнить что за параметр
            'fizyurType'    => $attributes['clientType'],
            'memo'          => DB::mysqli()->real_escape_string($attributes['clientComment']),
            'ym_id'         => $attributes['ym_id'],
            'rnomer'        => $specialComment,

            'dostdata'      => DB::mysqli()->real_escape_string($attributes['deliveryDate']),
            'dosttime'      => 'с 20 до 25',
            'vyvozdata'     => DB::mysqli()->real_escape_string($attributes['deliveryDate']),
            'vyvozmesto'    => 'Волгоградский',
            'vyvoztime'     => 'с 19 до 21',
            'deliveryType'  => $attributes['deliveryType'],
            'dostprice'     => $attributes['deliveryPrice'],

            'tovar_info'    => serialize($goods),
            'itog'          => $attributes['goodsCost'],
            'prisut'        => implode(';', $prisut),
            'newformat'     => 2,   // TODO: уточнить что за параметр
            'ubonus'        => 0,
            'tk'            => '',

            'phone_inner'   => DB::mysqli()->real_escape_string($client['phone']),
            'normalphone'   => DB::mysqli()->real_escape_string($client['phone']),
            'fam_inner'     => DB::mysqli()->real_escape_string($client['name']),
            'email_inner'   => DB::mysqli()->real_escape_string($client['email']),

            'gorod'         => DB::mysqli()->real_escape_string($deliveryAddress['city']),
            'indeks'        => DB::mysqli()->real_escape_string($deliveryAddress['postcode']),
            'ulica'         => DB::mysqli()->real_escape_string($deliveryAddress['street']),
            'dom'           => DB::mysqli()->real_escape_string($deliveryAddress['house']),
            'korp'          => DB::mysqli()->real_escape_string($deliveryAddress['block']),
            'podezd'        => DB::mysqli()->real_escape_string($deliveryAddress['entrance']),
            'kod'           => DB::mysqli()->real_escape_string($deliveryAddress['entryphone']),
            'kv'            => DB::mysqli()->real_escape_string($deliveryAddress['apartment']),
            'etaz'          => DB::mysqli()->real_escape_string($deliveryAddress['floor']),
            'metro'         => DB::mysqli()->real_escape_string($deliveryAddress['subway'])
        ];
        if(!empty($attributes['ym_id'])){
            $offer['ym_id'] = $attributes['ym_id'];
        }
        if(!empty($attributes['ym_status'])){
            $offer['ym_status'] = $attributes['ym_status'];
        }
        if(!empty($attributes['ym_substatus'])){
            $offer['ym_substatus'] = $attributes['ym_substatus'];
        }
        // создаём заказ
        $insertQuery = '
            INSERT INTO
                '.OFFERS_TABLENAME.'(
                    `kogda`,
                    `ip`,
                    `'.implode('`, `', array_keys($offer)).'`,
                    `hide_in_logistic`
                )
            VALUES(
                NOW(),
                \''.$_SERVER["REMOTE_ADDR"].'\',
                \''.implode('\', \'', $offer).'\',
                '.(empty($attributes['hide_in_logistic']) ? 'NULL' : $attributes['hide_in_logistic']).'
            )
        ';
        if(DB::mysqli()->query($insertQuery)){
            // успешно
            $offerID = DB::mysqli()->insert_id;
            // дублируем номер заказа
            $updateQuery = '
                UPDATE
                    '.OFFERS_TABLENAME.'
                SET
                    `nomer`='.$offerID.'
                WHERE
                    `id`='.$offerID.'
            ';
            DB::mysqli()->query($updateQuery);
//            if(!DB::mysqli()->query($updateQuery)){
//                echo($updateQuery);
//                echo(DB::mysqli()->error);
//            }
            // в ответ вставляем объект заказ
            $createdOffer = self::Id($offerID);
            // дублируем товары заказа в отдельную таблицу
            $createdOffer->setGoods($goods);
            // лог
            LogModifi('art_shop_zakazy', $offerID, 'new');
        }
//        else{
//            echo($insertQuery);
//            echo(DB::mysqli()->error);
//        }
        // ответ
        return $createdOffer;
    }

    /**
     * @param array $attributes массив обновляемых сттрибутов
     *
     * @return bool
     */
    public function update($attributes){
        if(!is_array($attributes)) {
            return FALSE;
        }
        foreach($attributes as $attrName=>$attrValue){
            if(is_null($attrValue)){
                $updateSet[] = '`'.$attrName.'`=NULL';
            }
            else{
                $updateSet[] = '`'.$attrName.'`=\''.DB::mysqli()->real_escape_string($attrValue).'\'';
            }
        }
        LogModifi('art_shop_zakazy', $this->_currentOfferId, 'init');
        $updateQuery = '
            UPDATE
                '.OFFERS_TABLENAME.'
            SET
                '.implode(', ', $updateSet).'
            WHERE
                `id`='.$this->_currentOfferId.'
        ';
        $result = DB::mysqli()->query($updateQuery);
        if($result === FALSE) {
            LOGS::addMsg(self::LOG_SCOPE, 'Ошибка обновления заказа: ' . DB::mysqli()->error, LOGS::ERROR);
            return FALSE;
        }
        LogModifi('art_shop_zakazy', $this->_currentOfferId);
        return TRUE;
    }
	public function setGoods($inGoods) {
		$fields = &$this->_fieldInGoods;
		$existGoods = $this->getGoods();
		if ($existGoods === FALSE) {
			return FALSE;
		}
		$updateGoods = array(); $deleteGoods = array(); $goods = array();
		foreach($inGoods as $good){
			if (isset($good[0]) && isset($good[1]) && isset($good[2]) ){
				$key = intval($good[0]);
				$goods[$key]['good_id'] = $key;
				$goods[$key]['price'] = intval($good[1]);
				$goods[$key]['good_count'] = intval($good[2]);
				$goods[$key]['action_type'] = '';
				$goods[$key]['action_id'] = '';
			} else {
				$key = intval($good['good_id']);
				$goods[$key]['good_id'] = intval($good['good_id']);
				$goods[$key]['price'] = intval($good['price']);
				$goods[$key]['good_count'] = intval($good['good_count']);
				$goods[$key]['action_type'] = isset($good['action_type']) ? $good['action_type'] : '';
				$goods[$key]['action_id'] = isset($good['action_id']) ? $good['action_id'] : '';
			}
			if (key_exists($key, $existGoods)) {
				foreach($fields as $field){
					if($goods[$key][$field] != $existGoods[$key][$field]) {
						if ($goods[$key]['good_count'] > 0) $updateGoods[] = $goods[$key]; else $deleteGoods[] = $key;
						break;
					}
				}
				unset($goods[$key], $existGoods[$key]);
			}
		}
		unset($inGoods);
		// удаление товаров, которые остались в $existGoods, ибо их нет теперь в заказе.
		if (count($existGoods)) 
			foreach($existGoods as $key => $good)
				$deleteGoods[] = $key;
		if (count($deleteGoods)) {
			if( $this->_deleteGoods($deleteGoods) === FALSE) return FALSE;
		}
		if (count($goods)) {
			if( $this->_addGoods($goods) ===  FALSE) return FALSE;
		}
		//обновление товаров
		$sql_ = 'UPDATE `' . self::TableNameGoods.'` SET  ';
		foreach($updateGoods as $good){
			
			$sql = $sql_ .'`price` = '.$good['price'] . ', `good_count` = ' . $good['good_count'] . 
					' WHERE `good_id` = '.$good['good_id'].' AND `offer_id` = '.$this->_currentOfferId;
			
			if(FALSE === DB::mysqli()->query($sql)) 
				return $this->_error('Error Sql: ['.$sql.'] ', __LINE__);
			// обновляем товар в конкретном заказе
			$this->_offers[$this->_currentOfferId]['goods'][$good['good_id']] = $good;
		}
		CACHE::memcache()->set($this->_keyForGoodsMem.$this->_currentOfferId, $this->_offers[$this->_currentOfferId]['goods'], self::TimeCacheGoods);
		return TRUE;
	}
	protected function _addGoods($goods){
				
		$sql = 'INSERT IGNORE INTO `'.self::TableNameGoods.'` (`offer_id`,`good_id`,`price`, `price_first`, `good_count`, `kogda`) ';
		$values = array(); $actions = array();
		foreach($goods as $good){
			$actions[] = $good['good_id'];
			$price_first = GOOD::id($good['good_id'])->price;
			$values[] = '(\''.$this->_currentOfferId.'\', \''.$good['good_id'].'\', \''.$good['price'].'\', \''.$price_first.'\', \''.$good['good_count'].'\', NOW())';
			$this->_offers[$this->_currentOfferId]['goods'][$good['good_id']] = $good;
		}
		switch(count($values)) {
			case 0 : return FALSE;
			case 1 : $sql .= ' VALUE '.$values[0];
				break;
			default:
				$sql .= ' VALUES '.implode(' , ', $values);
		}
		$res = DB::mysqli()->query($sql);
		if (FALSE === $res) return $this->_error('Error Sql: ['.$sql.'] ', __LINE__);
		
		if (count($actions)) {
			$actDelivery = GOODS::isDeliveryFree($actions);
			$actAllGoods = PROMOTIONS::Goods(TRUE);
			$actThis = array();
			foreach($actions as $good_id) {
				if (array_key_exists($good_id, $actAllGoods)) {
//					foreach($actAllGoods[$good_id]['promotion_id'] as $actId)
//						$actThis[$actId][] = $good_id;
						$actThis[ $actAllGoods[$good_id]['promotion_id'] ][] = $good_id;
				}
			}
		}
		// БП доставка
		if (count($actDelivery) > 0) {
			$sql = 'UPDATE '.self::TableNameGoods.' SET `action_type` = \'free_delivery\' WHERE `good_id` IN('.implode(',', $actDelivery).') AND `offer_id` = '.$this->_currentOfferId;
			$res = DB::mysqli()->query($sql);
			if (FALSE === $res) return $this->_error('Error Sql: ['.$sql.'] ', __LINE__);
			
			foreach($actions as $good) {
				$this->_offers[$this->_currentOfferId]['goods'][$good]['action_type'] = 'free_delivery';
				$this->_offers[$this->_currentOfferId]['goods'][$good]['action_id'] = array();
			}
		}
		// другие акции
		if (count($actThis) > 0) foreach($actThis as $actId => $actGoods){
			$sql = 'UPDATE '.self::TableNameGoods.' SET `action_type` = \'promotions\', action_id = \''.$actId.'\' WHERE `good_id` IN('
					.implode(',', $actGoods).') AND `offer_id` = '.$this->_currentOfferId;
			$res = DB::mysqli()->query($sql);
			if (FALSE === $res) return $this->_error('Error Sql: ['.$sql.'] ', __LINE__);
			foreach($actions as $good) {
				$this->_offers[$this->_currentOfferId]['goods'][$good]['action_type'] = 'promotions';
				$this->_offers[$this->_currentOfferId]['goods'][$good]['action_id'] = [$actId];
			}
		}
		CACHE::memcache()->delete($this->_keyForGoodsMem.$this->_currentOfferId);
		return TRUE;
	}
	protected function _deleteGoods($goods){
		if (!count($goods)) return FALSE;
		$sql = 'DELETE FROM '.self::TableNameGoods.' WHERE good_id IN('.implode(',', $goods).') AND offer_id = '. $this->_currentOfferId;
		$res = DB::mysqli()->query($sql);
		if ($res === FALSE) return $this->_error('Error Sql: ['.$sql.'] ', __LINE__);
		foreach($goods as $good) {
			unset($this->_offers[$this->_currentOfferId]['goods'][$good]);
		}
		CACHE::memcache()->delete($this->_keyForGoodsMem.$this->_currentOfferId);
	}
	public function getGoods($offer_id = FALSE){
		
		$offer_id = $offer_id === FALSE ? $this->_currentOfferId : $offer_id;
		// TODO: убрать обратную совместимость содержания массива товаров.
		$return = array();
		if (isset($this->_offers[$offer_id]['goods'])) 
			if(!empty($this->_offers[$offer_id]['goods'])) {
				$return = $this->_offers[$offer_id]['goods'];
		} 
		else {
			$return = $this->_getGoods($offer_id);
		}
		// для обратной совместимости /*
		if (count($return)){
			foreach($return as &$good){
				foreach($this->_fieldInGoods as $key => $field) $good[$key] = $good[$field];
			}
		}
		// для обратной совместимости */
		return $return;
	}
	protected function _getGoods($offer_id){
		$goods = CACHE::memcache()->get($this->_keyForGoodsMem.$offer_id);
		
		if ($goods) return $goods;
		
		$sql = 'SELECT `'.implode('`, `', $this->_fieldInGoods).'` FROM '.self::TableNameGoods.' WHERE `offer_id` = '.$offer_id.' ORDER BY `kogda`';
		$res = DB::mysqli()->query($sql);
		if (false === $res) return $this->_error('$offer_id: '.$offer_id.', Error Sql: '.$sql, __LINE__);
		if ($res->num_rows < 1) return array();
		
		$goods = array();
		while($row = $res->fetch_assoc()){
			 foreach($this->_fieldInGoods as $field) 
				 $goods[$row['good_id']][$field] = $row[$field];
		}
		$res->free();
		if (count($goods)) {
			$this->_offers[$offer_id]['goods'] = $goods;
			CACHE::memcache()->set($this->_keyForGoodsMem.$offer_id, $goods, self::TimeCacheGoods);
		}
		return $goods;
	}
	public function __isset($name) {
        if($this->_currentOfferId){
            if(isset($this->_offers[$this->_currentOfferId][$name])){
                return TRUE;
            }
        }
        return FALSE;
    }
	public function __get($name){
		if($this->_currentOfferId){
			if(isset($this->_offers[$this->_currentOfferId][$name])){
				return $this->_offers[$this->_currentOfferId][$name];
			}
		}
		return NULL;
	}
	protected function _loadOffers($offers_ids, $refreshFlag = FALSE){
		if(!$refreshFlag){
			if(count($offers_ids) === 1){
				if(isset($this->_offers[$offers_ids[0]])){
					$this->_currentOfferId = $offers_ids[0];
					return TRUE;
				}
			}
		}
		// Убираем неправильные значения из списка (вроде ЗчТ-…, РТ-…, etc)
        $offers_ids = array_filter($offers_ids, function ($val){
            if(0 < (int) $val) {
                return TRUE;
            }
            $backtrace = \Regard\Utils\Strings\Strings::backtrace2string(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
            LOGS::addMsg(self::LOG_SCOPE, "Неправильное значение ID: {$val}\n" . $backtrace);
            return FALSE;
        });
		// Если $offers_ids == array(NULL), то вышестоящие проверки успешно проходят и тут получится пустая строка
		$offers = implode(',', $offers_ids);
		if($offers === '') {
			return FALSE;
		}
		$query = '
			SELECT
				*
			FROM
				`'.OFFERS_TABLENAME.'`
			WHERE
				`id` IN (' . $offers . ')
		';
		DB::$queriesHistory[] = $query;
		$queryOffer = DB::mysqli()->query($query);
		if($queryOffer){
			if($queryOffer->num_rows > 0){
				while($offer = $queryOffer->fetch_assoc()){
					$this->_offers[$offer['id']] = $offer;
					$this->_offers[$offer['id']]['goods'] = $this->_getGoods($offer['id']);
				}
				$this->_loadRelationData();
				$this->_completeData();
				$this->_loadReserveData($offers_ids);
				$this->_currentOfferId = FALSE;
				if(count($offers_ids) === 1){
					$this->_currentOfferId = $offers_ids[0];
				}
				return TRUE;
			}
		} else 
			$this->_error('Error Sql: '.$query, __LINE__);
		return FALSE;
	}
	protected function _loadRelationData(){
		$offersIds = array_keys($this->_offers);
		foreach(RELATIONS::Dependence(self::TableName, $offersIds) as $offerId=>$offerRelations){
			foreach($offerRelations as $relationName=>$relationValue_inArray){
				$this->_offers[$offerId][$relationName] = $relationValue_inArray;
			}
		}
//		$this->_offers = array_merge_recursive(
//			$this->_offers,
//			RELATIONS::Dependence(self::TableName, $offersIds)
//		);
	}
	protected function _completeData(){
		$driversFamilies = array();
		foreach($this->_offers as $offerId=>&$offer){
			$driversFamilies = array();
			$driversNames = array();
			$driversPhones = array();
			if(isset($offer['driver_id'])){
				foreach($offer['driver_id'] as $driverId){
					$driversFamilies[] = DRIVERS::Id($driverId)->family;
					$driversNames[] = DRIVERS::Id($driverId)->name;
					$driversPhones[] = DRIVERS::Id($driverId)->phone;
				}
			}
			$offer['driverFamily'] = implode(',', $driversFamilies);
			$offer['driverName'] = implode(',', $driversNames);
			$offer['driverPhone'] = implode(',', $driversPhones);
		}
	}
	protected function _loadReserveData($offers_ids){		
		if($reserveInfo = RESERVE::GetStatus_byOffers($offers_ids)){
			foreach($reserveInfo as $offerID=>$reserveOfferInfo){
				if(!empty($this->_offers[$offerID])){
					$this->_offers[$offerID]['reserveInfo'] = $reserveOfferInfo;
				}
			}
		}
	}
	public function getInfo(){
		if($this->_currentOfferId){
			return $this->_offers[$this->_currentOfferId];
		}
		else{
			return $this->_offers;
		}
	}
    public function setDrivers($driversFamilyNames = array(), $flag_refreshDriversCache = TRUE){
        if(!is_array($driversFamilyNames)){
            $driversFamilyNames = (strpos($driversFamilyNames, ',')) ? explode(',', $driversFamilyNames) : array($driversFamilyNames);
        }
		// заменяем фамилии на айди
		$driversIDs = DRIVERS::FamilyList($driversFamilyNames)->id;
        // выставляем курьера
        $driversIDs_relationFormat = array_count_values($driversIDs);
        $relationUpdateResult = RELATIONS::Update(OFFERS::TableName, $this->_currentOfferId, DRIVERS::TableName, $driversIDs_relationFormat);
        // лог, кэш
        if($relationUpdateResult !== FALSE){
			global $GlField;
            // текущее значение (для лога)
            $old_driver_value = '';
            if(isset($this->_offers[$this->_currentOfferId]['driver_id'])){
                $old_driver_value = $this->_offers[$this->_currentOfferId]['driverFamily'];
            }
            // кто меняет
            $login = (empty($_SESSION['user_login0']) ? $GlField['USERLOGIN'] : $_SESSION['user_login0']);
            // добавляем запись в лог заказа
            HISTORY::Add(
                $login,
                $this->_currentOfferId,
                'DRIVER',
                implode(',', $driversFamilyNames),
                $old_driver_value
            );
            // обновляем кэш "заказы курьера/ов"
            if($flag_refreshDriversCache){
                DRIVERS::FamilyList($driversFamilyNames)->refreshRelationCache();
            }
        }
    }
	protected function _getDelivery($day = 'today'){
        $status = '1,5,8,6';
		switch($day){
			case 'afterTomorrow':
				$date = '`dostdata`=(CURDATE() + INTERVAL 2 DAY)';
				break;
			case 'tomorrow':
				$date = '`dostdata`=(CURDATE() + INTERVAL 1 DAY)';
				break;
			case 'today':
			default:
                $status = '5,8,6';
				$date = '`dostdata`>\'0000-00-00\' AND `dostdata`<=CURDATE()';
				break;
		}
		$where = '
			`status` in ('.$status.')
			AND
			'.$date.'
			AND
			`deliveryType`=1
            AND
            `flgoplata`<>3
		';
		return $this->_getCounters(FALSE, $where, 92, 'DAY');
	}
	protected function _getDeliveryInProcess($day = 'today'){
		switch($day){
			case 'afterTomorrow':
				$date = '(CURDATE() + INTERVAL 2 DAY)';
				break;
			case 'tomorrow':
				$date = '(CURDATE() + INTERVAL 1 DAY)';
				break;
			case 'today':
			default:
				$date = 'CURDATE()';
				break;
		}
		$where = '
			`status`=4
			AND
			`dostdata`='.$date.'
			AND
			`deliveryType`=1
		';
		return $this->_getCounters(FALSE, $where, 92, 'DAY');
	}
	protected function _getDeliveryDone(){
		$where = '
			`status`=5
			AND
			`deliveryType`=1
			AND
			(
				statuskomp = \'\'
				OR
				(
					statuskomp NOT LIKE \'%Ожидание%\'
					AND
					statuskomp NOT LIKE \'%Подготовка%\'
					AND
					statuskomp NOT LIKE \'%Брак%\'
					AND
					statuskomp NOT LIKE \'%Сборка%\'
				)
			)
            AND
            `flgoplata`<>3
		';
		return $this->_getCounters(FALSE, $where, 186, 'DAY');
	}
	protected function _getDeliveryTK($state = 'process'){
		$where = '';
		$kompStatus = '';
		switch($state){
			case 'done':
				$status = 5;
                $where = ' `flgoplata`<>3 AND `tk`<>\'СДЭК\' AND `tk`<>\'\' AND ';
				$kompStatus = 'AND (`statuskomp`=\'\' OR (`statuskomp` NOT LIKE \'%Ожидание%\' AND `statuskomp` NOT LIKE \'%Подготовка%\' AND `statuskomp` NOT LIKE \'%Брак%\' AND `statuskomp` NOT LIKE \'%Сборка%\'))';
				break;
			case 'doneCDEK':
				$status = 5;
                $where = '`flgoplata` IN (1,4) AND `tk`=\'СДЭК\' AND ';
				$kompStatus = 'AND (`statuskomp`=\'\' OR (`statuskomp` NOT LIKE \'%Ожидание%\' AND `statuskomp` NOT LIKE \'%Подготовка%\' AND `statuskomp` NOT LIKE \'%Брак%\' AND `statuskomp` NOT LIKE \'%Сборка%\'))';
				break;
			case 'processCDEK':
				$status = 8;
                $where = '`flgoplata`<>3 AND `tk`=\'СДЭК\' AND ';
				$kompStatus = 'OR (`status`=5 AND (`statuskomp` LIKE \'%Ожидание%\' OR `statuskomp` LIKE \'%Подготовка%\' OR `statuskomp` LIKE \'%Брак%\' OR `statuskomp` LIKE \'%Сборка%\'))';
				$kompStatus .= 'OR (`status`=5 AND `flgoplata`=5)';
				break;
			case 'process':
			default:
				$status = 8;
				$where = '`flgoplata`<>3 AND `tk`<>\'СДЭК\' AND `tk`<>\'\' AND ';
				$kompStatus = 'OR (`status`=5 AND (`statuskomp` LIKE \'%Ожидание%\' OR `statuskomp` LIKE \'%Подготовка%\' OR `statuskomp` LIKE \'%Брак%\' OR `statuskomp` LIKE \'%Сборка%\'))';
				$kompStatus .= 'OR (`status`=5 AND `flgoplata`=5)';
				break;
		}
		$where .= '
			(
				`status`='.$status.'
				'.$kompStatus.'
			)
			AND
			`deliveryType`=1
			AND
			(
				LENGTH(`gorod`)>0
			)
		';

		return $this->_getCounters(FALSE, $where, 92, 'DAY');
	}
	protected function _getComputers($state = 'wait'){
		$statuskomp = array();
		$operation = 'LIKE';
		switch($state){
			case 'done':
				$statuskomp[] = 'Готов ';
				break;
			case 'process':
				$statuskomp[] = 'Сборка';
				break;
			case 'defect':
				$statuskomp[] = 'Брак';
				break;
			case 'wait':
			default:
				$statuskomp[] = 'Ожидание';
				$statuskomp[] = 'Подготовка';
				break;
		}
		foreach($statuskomp as &$compStatus){
			$compStatus = '`statuskomp` '.$operation.' \'%'.$compStatus.'%\'';
		}
		$where = '
			(
				`status` IN (1,5,8)
				OR
				(
					`status`=6
					AND
					`prichina`=\'\'
				)
			)
			AND
			(
				'.implode(' OR ', $statuskomp).'
			)
		';
		return $this->_getCounters(FALSE, $where, 3);
	}
	protected function _getComputersWait($day = 'today'){
		switch($day){
			case 'afterTomorrow':
				$dostdata = ' + INTERVAL 2 DAY';
				break;
			case 'tomorrow':
				$dostdata = ' + INTERVAL 1 DAY';
				break;
			case 'today':
			default:
				$dostdata = '';
				break;
		}
		$where = '
			`status` IN (1,6,5,8)
			AND
			(
				`statuskomp` LIKE \'%Ожидание%\'
				OR
				`statuskomp` LIKE \'%Подготовка%\'
				OR
				`statuskomp` LIKE \'%Сборка%\'
				OR
				`statuskomp` LIKE \'%Брак%\'
			)
			AND
			(
				(
					`dostdata` = (CURDATE()'.$dostdata.')
					AND
					`deliveryType`=1
				)
				OR
				(
					`vyvozdata` = (CURDATE()'.$dostdata.')
					AND
					`deliveryType`=2
				)
			)
		';
		return $this->_getCounters(FALSE, $where, 3);
	}
	protected function _get1C_fail($manager_login = FALSE){
		$where = '
			`status` = 1
			AND
			fizyurType != 2
			AND
			payType != 2
		';
		return $this->_getCounters($manager_login, $where, 1);
	}
	protected function _getUnPrinted($manager_login = FALSE){
		$where = '
			`status` = 8
			AND
			fizyurType != 2
			AND
			payType != 2
			AND
			`id` IN(
				SELECT DISTINCT(`changeLog`.`rec_id`)
				FROM
					`art_shop_log_n` as `changeLog`
				WHERE
					`changeLog`.`fldname` = \'status\'
					AND
					`changeLog`.`kogda` BETWEEN (CURDATE() - INTERVAL 1 DAY) AND (NOW() - INTERVAL 10 MINUTE)
					AND
					`changeLog`.`new` = \'8\'
					AND
					`changeLog`.`rec_id` NOT IN(
						SELECT DISTINCT(`changeLog2`.`rec_id`)
						FROM
							`art_shop_log_n` as `changeLog2`
						WHERE
							`changeLog2`.`fldname` = \'CREATE_ZAKAZ\'
							AND
							`changeLog2`.`kogda` BETWEEN (CURDATE() - INTERVAL 1 DAY) AND (NOW() - INTERVAL 10 MINUTE)
							AND
							`changeLog2`.`who` = \'выгрузка 1с\'
					)
			)
			AND
			`id` NOT IN(
				SELECT DISTINCT(`printLog`.`zakaz_id`)
				FROM
					`log__printZakaz` as `printLog`
				WHERE
					`printLog`.`kogda` > (CURDATE() - INTERVAL 1 DAY)
			)
		';
		return $this->_getCounters($manager_login, $where, 1, 'DAY');
	}
	protected function _getReturned($manager_login = FALSE){
		$where = '
			`status` = 4
			AND
            `fizyurType`<>2
			AND
            `payType`<>2
			AND
			(
				`rnomer` LIKE \'%Возврат:%\'
				OR
				`prim` LIKE \'%Возврат:%\'
				OR
				`rkp` LIKE \'%Возврат:%\'
				OR
				`dostinfo` LIKE \'%Возврат:%\'
			)
		';
		return $this->_getCounters($manager_login, $where, 1);
	}
	protected function _getOnline($manager_login = FALSE){
		$where = '
			`payType` = 3
            AND
            `status` IN (0,1,4,5,8,9)
            AND
            `flgoplata` IN (0,3)
		';
		return $this->_getCounters($manager_login, $where, 31, 'DAY');
	}
	protected function _getReserveExceeded($manager_login = FALSE){
		$where = '
			`status` = 6
			AND
			`itog` > 9999
			AND
			`prichina` = \'\'
		';
		return $this->_getCounters($manager_login, $where, 1);
	}
	protected function _getExceeded($manager_login = FALSE){
		$where = '
			`status` IN (1,5,8)
			AND
			`deliveryType` = 1
			AND
			`dostdata` > \'00.00.0000\'
			AND
			`dostdata` < CURDATE()
		';
		return $this->_getCounters($manager_login, $where, 1);
	}
	protected function _getFresh($manager_login = FALSE){
		$payType = -1;
		if(!empty($_SESSION['user_department']['settings']['filter_payType'])){
			$payType = $_SESSION['user_department']['settings']['filter_payType'];
		}
		$where = '
			(
				`status` IN (0,9)
				OR
				`manager`=\'\'
			)
			'.(($payType > 0) ? 'AND `payType`='.$payType : '').'
		';
		return $this->_getCounters($manager_login, $where, 1);
	}
	protected function _getYM() {
        $where = '`ym_id` IS NOT NULL';
        return $this->_getCounters(FALSE, $where, 92, 'DAY');
    }
	protected function _getObserved($manager_login = FALSE){
		$data_minus_60 = date("Y-m-d H:i:s", time()-3600);
		$tranzit_NextDay = (date("H") < 12) ? '' : 'OR IF(
							`deliveryType` = 1,
							((`dostdata` >= (CURDATE() + INTERVAL 1 DAY)) AND (`dostdata` < (CURDATE() + INTERVAL 2 DAY)) AND (substr(`dosttime` , 2, 3) < 18)),
							((`vyvozdata` >= (CURDATE() + INTERVAL 1 DAY)) AND (`vyvozdata` < (CURDATE() + INTERVAL 2 DAY)) AND (substr(`vyvoztime` , 2, 3) < 18))
						)
		';
		$where = '
			(
				(
					`status`=8
					AND
					(
						`privoz` = \'\'
						OR
						`privoz` LIKE \'КРАСНЫЙ%\'
					)
					AND
					(
						IF(
							`deliveryType` = 1,
							((`dostdata` >= CURDATE()) AND (`dostdata` < (CURDATE() + INTERVAL 1 DAY))),
							((`vyvozdata` >= CURDATE()) AND (`vyvozdata` < (CURDATE() + INTERVAL 1 DAY)))
						)
						'.$tranzit_NextDay.'
					)
				)
				OR `id` IN(
					SELECT
						Z.id
					FROM
						art_shop_zakazy AS Z,
						art_shop_log_n AS L
					WHERE
						Z.status = 1
						AND Z.id = L.rec_id
						AND ((`L`.`new` = 1
                        AND `L`.`fldname` = \'status\') OR `L`.`fldname` = \'CREATE_ZAKAZ\')
						AND L.kogda < \''.$data_minus_60.'\'
				)
				OR `id` IN(
					SELECT
						Z.id
					FROM
						art_shop_zakazy AS Z,
						art_shop_log_n AS L
					WHERE
						Z.id = L.rec_id
						AND L.fldname = \'status\'
						AND L.old = 5
						AND Z.status NOT IN (3,4,5,6,7)
						AND L.kogda >= (CURDATE() - INTERVAL 7 DAY)
				)
                OR `id` IN(
                    SELECT
                        Z.id
                    FROM
                        art_shop_zakazy AS Z
                    WHERE
                        `status`<>3
                        AND `ym_id` IS NOT NULL
                        AND `ym_status`=\'CANCELLED\'
                )
			)
		';
		return $this->_getCounters($manager_login, $where, 1);
	}
	protected function _getCount($manager_login = FALSE){
		$query = '
			SELECT
				COUNT(*) as `offersCount`
			FROM
				`'.OFFERS_TABLENAME.'` FORCE INDEX(kogda)
			WHERE
				`kogda`>(CURDATE() - INTERVAL 3 MONTH)
				'.(($manager_login) ? 'AND `manager` = \''.$manager_login.'\'' : '').'
			;
		';
		DB::$queriesHistory[] = $query;
		$queryOffer = DB::mysqli()->query($query);
		if($queryOffer){
			$row = $queryOffer->fetch_assoc();
			return $row['offersCount'];
		} else $this->_error('Error Sql: '.$query, __LINE__);
		return 0;
	}
	protected function _getCounters($manager_login = FALSE, $where = FALSE, $periodValue = FALSE, $periodType = 'MONTH'){
		$counters = array(
			'departments'	=> array(
				'1'	=> 0,
				'2'	=> 0
			),
			'managers'		=> array(),
			'manager'		=> 0,
			'full'			=> 0
		);
		$queryCount = DB::mysqli()->query('
			SELECT
				`manager`,
				`payType`,
				`fizyurType`,
				COUNT(*) as `offersCount`
			FROM
				`'.OFFERS_TABLENAME.'`
			WHERE
				`kogda`>(CURDATE() - INTERVAL 1 YEAR)
				AND `hide_in_logistic` IS NULL
				'.(($periodValue) ? 'AND `kogda`>(CURDATE() - INTERVAL '.$periodValue.' '.$periodType.')' : '').'
				'.(($where) ? 'AND '.$where : '').'
			GROUP BY
				`manager`,
				`payType`,
				`fizyurType`
			;
		');
		if($queryCount){
			while($row = $queryCount->fetch_assoc()){
				$counters['full'] += $row['offersCount'];
				$departmentFlag = $row['fizyurType'].'_'.$row['payType'];
				switch($departmentFlag){
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
		} else $this->_error('Error Sql! $where: '.$where, __LINE__);
		return $counters;
	}
	public function get_errors($delimeter = ''){
		if (!$delimeter){
			echo "<pre>"; print_r($this->_errors); echo "</pre>\n";
			return '';
		} else return implode($delimeter, $this->_errors);
	}
	protected function _error($text, $line = 0){
		$mysql_error = DB::mysqli()->error;
		LOGS::addMsg('offers', 'Line: '.$line.($mysql_error ? ', MySqli error: '.$mysql_error : '').', Msg: '.$text);
		$this->_errors[$line][] = $text; return FALSE;
	}
	public static function GetStatusCaption($status){
		$statusCaption = $status;
		switch($status){
			case '0':	$statusCaption =  '[<b>новый</b>]';																										break;
			case '1':	$statusCaption =  '<font color=magenta>[подтвержден]</font>';																			break;
			case '7':	$statusCaption =  '<font color=#6C7B8B><b>[выставлен счет]</b></font>';																	break;
			case '2':	$statusCaption =  '<font color=green>[оплачен]</font>';																					break;
			case '3':	$statusCaption =  '<font color=red>[аннулирован]</font>';																				break;
			case '6':	$statusCaption =  '<font color=red>[срок резерва]</font>';																				break;
			case '4':	$statusCaption =  '<font color=blue>[отгружен]</font>';																					break;
			case '5':	$statusCaption =  '<b style="color:#005B7F;">[готов&nbsp;к&nbsp;отгрузке]</b>';															break;
			case '8':	$statusCaption =  '<b style="color:#96DC19;text-shadow:#fff 0 0 2px,#fff 0 0 3px,#fff 0 0 3px,#fff 0 0 3px;">[в&nbsp;обработке]</b>';	break;
			case '9':	$statusCaption =  '<b style="background-color:red;color:white;">[телефон]</b>';															break;
			case '91':	$statusCaption =  '<b style="background-color:red;color:white;">Кредит - ожидание разрешения</b>';										break;
		}
		return $statusCaption;
	}
    
    /*
     * Получить значение всех цветов указанных заказов
     * 
     * @param array $offers_id Массив id заказов
     * @return array|null
     */
    public static function getColor($offers_id) {
        if ( !is_array($offers_id) || count($offers_id)<=0 ) {
            return null;
        }
        
        $query = 'SELECT `R`.`from_id` AS `id`, `D`.`color`
                    FROM `relations__main` AS `R`
                        JOIN `drivers` AS `D`
                            ON  `D`.`id` = `R`.`to_id` 
                            AND `R`.`from_id` IN(' . implode(',', $offers_id) . ')';
        $result = DB::mysqli()->query($query);
        $data = [];
        while($f = $result->fetch_assoc()) {
            $color = DRIVERS::DEFAULT_COLOR;
            if (!empty($f['color'])) {
                $color = $f['color'];
            }
            $color = substr($color, 1); // убираем #
            $data[$f['id']] = $color;
        }
        return $data;
    }

    public function getCardnumber() {
        if((int) $this->payType === self::PAYTYPE_ONLINE && in_array($this->flgoplata, [1, 4])) {
            $query = 'SELECT `cardnumber` FROM `onlinepay_log` WHERE `offer_id` = ' . (int) $this->id;
            $result = DB::mysqli($query);
            if(count($result) === 0) {
                $message = 'Не удалось найти запись об оплате заказа ' . $this->id;
                LOGS::addMsg(self::LOG_SCOPE, $message, LOGS::ERROR);
                throw new InvalidArgumentException($message);
            }
            return '****' . substr($result[0]['cardnumber'], -4, 4);
        }
        return '';
    }

    public function getFirstTransactionMntId() {
        $onlinepay = new ONLINE_PAY();
        $onlinepay->setOffer($this->id);
        $transactions = $onlinepay->getTransactions();
        return substr(reset($transactions)['operation_id'], -4, 4);
    }

    public static function cleanDrivers($cleanDate = NULL) {
        $statuses = [
            self::STATUS_CONFIRMED,
            self::STATUS_PROCESSING,
            self::STATUS_READY
        ];
        $deliveryDate = 'CURDATE()';
        $deliveryOperator = '>=';
        switch($cleanDate){
            case 'today':
                $deliveryOperator = '=';
                break;
            case 'tomorrow':
                $deliveryDate = '(CURDATE() + INTERVAL 1 DAY)';
                $deliveryOperator = '=';
                break;
            case 'afterTomorrow':
                $deliveryDate = '(CURDATE() + INTERVAL 2 DAY)';
                $deliveryOperator = '=';
                break;
        }
        $whereDeliveryDate = '`dostdata`'.$deliveryOperator.$deliveryDate;
        $whereStatus = '`status` IN ('.(implode(',', $statuses)).')';
        $sql = "SELECT `id` FROM `art_shop_zakazy` WHERE {$whereDeliveryDate} AND {$whereStatus}";
        $result = DB::mysqli()->query($sql);
        if ($result !== FALSE) {
            while ($order = $result->fetch_assoc()) {
                // Очищаем водителей у выбранных заказов
                self::Id($order['id'])->setDrivers([], FALSE);
            }
            // Теперь надо перестроить кеш
            DRIVERS::FamilyList(array())->refreshRelationCache();
        }
        // TODO: логировать ошибки запроса
    }
}
