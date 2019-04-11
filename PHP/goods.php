<?php

class GOODS{

	const TABLENAME = 'art_shop_shop_goods';
	const TABLENAME_PROPERTIES = 'art_shop_property_goods';

	private static $_singleton = NULL;
	protected $_goods = array();
	protected $_lastGoodsIDsList = array();
	public $currentCount = 0;

    /**
     * Товары которые реализовываются только в составе сборки ПК
     * НЕЛЬЗЯ купить отдельно
     * @var int[]
     */
    public static $sale_only_in_cfg = [
    ];

	/**
	 * @return GOODS
	 */
	private static function _getInstance(){
		if(is_null(self::$_singleton)){
			$className = __CLASS__;
			self::$_singleton = new $className;
		}
		return self::$_singleton;
	}
	
	/**
	 * 
	 * @param int|int[] $goodsIDs
	 * @return \GOODS
	 */
	public static function IDs($goodsIDs = array()){
		return self::_getInstance()->_load($goodsIDs);
	}
	public static function List_byGroupID($group_id){
		return self::_getInstance()->_loadGroup($group_id);
	}
	public static function List_forGrabber($groupsToIgnore = array()){
		return self::_getInstance()->_load_forGrabber($groupsToIgnore);
	}
	public static function isDeliveryFree($goodsIDs = array()){
		$freeDelivery_goodsIDs = array();
		// в массив
		if(!is_array($goodsIDs)){
			$goodsIDs = array($goodsIDs);
		}
		// проверяем что числа
		foreach($goodsIDs as $key=>$goodID){
			if(!is_numeric($goodID)){
				unset($goodsIDs[$key]);
			}
		}
		// ищем товары по акции
		if(count($goodsIDs) > 0){
			$queryCheck = '
				SELECT
					`id`
				FROM
					'.self::TABLENAME.'
				WHERE
					`id` IN ('.implode(',', $goodsIDs).')
					AND
					`grpid` NOT IN('.SETTINGS::Get('proc_delivery_g').')
                    AND
                    `price` >= '.SETTINGS::Get('proc_delivery_bound').'
					AND
					`r_flag`=1
			';
			if($queryResult = DB::mysqli()->query($queryCheck)){
				if($queryResult->num_rows > 0){
					while($row = $queryResult->fetch_assoc()){
						$freeDelivery_goodsIDs[] = $row['id'];
					}
				}
			}
		}
		return $freeDelivery_goodsIDs;
	}
	protected function _load_forGrabber($groupsToIgnore = array()){
		// карточки маркета содержащие более одого нашего товара
		$duplicate_marketIDs = array();
		$querySelect_duplicateMarketIDs = '
			SELECT
				COUNT(*) as `duplicate`,
				`goods`.`market_id`
			FROM
				'.self::TABLENAME.' as `goods`
			WHERE
				`goods`.`market_id` IS NOT NULL
				AND
				`goods`.`show_flag` > 0
			GROUP BY
				`market_id`
			HAVING
				`duplicate` > 1
			ORDER BY
				`duplicate` DESC
		';
		if($querySelect_duplicateMarketIDs_result = DB::mysqli()->query($querySelect_duplicateMarketIDs)){
			while($row = $querySelect_duplicateMarketIDs_result->fetch_assoc()){
				$duplicate_marketIDs[] = $row['market_id'];
			}
		}
		else{
			// TODO: обработчик ошибок
			exit(DB::mysqli()->error.'<br />'.$querySelect_duplicateMarketIDs);
		}
		// группы не учавствующие в граббере
		$groupsCondition = '';
		if(!empty($groupsToIgnore) > 0){
			if(is_array($groupsToIgnore)){
				$groupsCondition = '
					AND
					`goods`.`grpid` NOT IN ('.implode(',', $groupsToIgnore).')
					AND
					`goods`.`grpid0` NOT IN ('.implode(',', $groupsToIgnore).')
				';
			}
		}
		// запрос товаров подходящих для граббера
		$querySelect_goodsForGrab = '
			SELECT
				`goods`.`id`,
				`goods`.`price`,
				`goods`.`yaphraza`,
				`goods`.`market_id`
			FROM
				'.self::TABLENAME.' as `goods`
			WHERE
				`goods`.`show_flag` > 0
				AND
				`goods`.`price` > 1000
				'.$groupsCondition.'
				AND(
					LENGTH(`goods`.`yaphraza`)>0
					OR(
						`goods`.`market_id` IS NOT NULL
						'.(empty($duplicate_marketIDs) ? '': 'AND `goods`.`market_id` NOT IN ('.implode(',', $duplicate_marketIDs).')').'
					)
				)
			ORDER BY
				`goods`.`id` ASC
		';
		if($querySelect_goodsForGrab_result = DB::mysqli()->query($querySelect_goodsForGrab)){
			$this->_lastGoodsIDsList = array();
			while($row = $querySelect_goodsForGrab_result->fetch_assoc()){
				$this->_goods[$row['id']] = $row;
				$this->_lastGoodsIDsList[] = $row['id'];
			}
			$this->currentCount = count($this->_lastGoodsIDsList);
		}
		else{
			// TODO: обработчик ошибок
			exit(DB::mysqli()->error.'<br />'.$querySelect_goodsForGrab);
		}
		return $this;
	}
	protected function _loadGroup($group_id){
		if(is_numeric($group_id)){
			$where = '
				`grpid`='.$group_id.'
				AND
				`show_flag`>0
				AND
				`present_flag`>0
			';
			list($sortByHara, $querySelect_goodsByGroupID) = self::getQuery_sortByHara($group_id, $where, FALSE, 1, TRUE, FALSE, TRUE);
			if($querySelect_goodsByGroupID_result = DB::mysqli()->query($querySelect_goodsByGroupID)){
				$this->_lastGoodsIDsList = array();
				while($row = $querySelect_goodsByGroupID_result->fetch_assoc()){
					$this->_goods[$row['id']] = $row;
					$this->_lastGoodsIDsList[] = $row['id'];
				}
				$this->currentCount = count($this->_lastGoodsIDsList);
			}
		}
		return $this;
	}
	public function exportInfo($flag_fullInfo = FALSE, $force_showHidden = FALSE){
		$exportData = array();
		foreach($this->_lastGoodsIDsList as $goodID){
			$currentGood = &$this->_goods[$goodID];
			// TODO: возможно добавить сообщение - что товар скрыт ?!
			if(($currentGood['present_flag'] == 0)&&($currentGood['show_flag'] == 0)&&(!$force_showHidden)){
				continue;
			}
			$exportGood = array();
			if($flag_fullInfo){
				$exportGood = array(
					'id'			=> (int)$goodID,
					'group_id'		=> (int)$currentGood['grpid'],
					'name'			=> $currentGood['title'],
					'prefix'		=> $currentGood['gpart'],
					'vendor'		=> $currentGood['vendor'],
					'vendorCode'	=> $currentGood['vendorcode'],
					'helpName'		=> $currentGood['origname'],
					'brief'			=> $currentGood['sm_text'],
					'about'			=> $currentGood['pripiska'],
					'properties'	=> $this->getProperties_forExport($goodID),
					'width'			=> (int)$currentGood['width'],
					'height'		=> (int)$currentGood['height'],
					'depth'			=> (int)$currentGood['depth'],
					'mass'			=> (int)$currentGood['mass'],
					'warranty'		=> array(
						'official'		=> !(bool)$currentGood['noofgar']
					),
					'pictures'		=> $this->getImages_forExport($goodID)
				);
				if(is_numeric($currentGood['gar'])){
					$exportGood['warranty']['months'] = sscanf($currentGood['gar'], '%d')[0];
				}
//				// TODO: доделать для ОБЩЕГО экспорта
//				if($_SERVER['HTTP_PARTNER_LOGIN'] == 'priqut'){
//					if(empty($exportGood['brief'])){
//						$exportGood['brief'] = $this->getBrief($currentGood['grpid'], $currentGood['properties']);
//					}
//				}
				// TODO: summaryHash - сохранять в базу - для использования в кратком описании
				$exportGood['summaryHash'] = md5(serialize($exportGood));
			}
			else{
				$exportGood = array(
					'id'			=> (int)$goodID,
					'group_id'		=> (int)$currentGood['grpid'],
					'name'			=> $currentGood['title'],
//					'prefix'		=> $currentGood['gpart'],
					'vendor'		=> $currentGood['vendor'],
					'vendorCode'	=> $currentGood['vendorcode'],
					'helpName'		=> $currentGood['origname']
				);
			}
			$exportData[] = $exportGood;
		}
		return $exportData;
	}
	public function getProperties_forExport($goodID){
		$propertiesForExport = array();
		if(!is_numeric($goodID)){
			return $propertiesForExport;
		}
		// значения характеристик товаров
		$querySelect_goodProperties = '
			SELECT
				*
			FROM
				`'.self::TABLENAME_PROPERTIES.'`
			WHERE
				`good_id`='.$goodID.'
		';
		if($querySelect_goodProperties_result = DB::mysqli()->query($querySelect_goodProperties)){
			while($row = $querySelect_goodProperties_result->fetch_assoc()){
				$goodProperties[$row['propy_id']][] = $row;
			}
			// тип характеристик
			$querySelect_properties = '
				SELECT
					`id`,
					`tip`
				FROM
					`art_shop_shop_param3`
				WHERE
					`id` IN ('.implode(',', array_keys($goodProperties)).')
			';
			if($querySelect_properties_result = DB::mysqli()->query($querySelect_properties)){
				while($row = $querySelect_properties_result->fetch_assoc()){
					foreach($goodProperties[$row['id']] as &$goodProperty){
						$goodProperty['tip'] = $row['tip'];
					}
				}
				// экспорт
				foreach($goodProperties as $propertyID=>&$propertyValues){
					$propertyForExport = array();
					$values = array();
					foreach($propertyValues as &$propertyValue_info){
						switch($propertyValue_info['tip']){
							case '1':
								$propertyForExport = array(
									'property_id'	=> $propertyID,
//									'propertyType'	=> 'INTEGER',
									'value'			=> (int)$propertyValue_info['value']
								);
								break;
							case '2':
								$propertyForExport = array(
									'property_id'	=> $propertyID,
//									'propertyType'	=> 'BOOL',
									'value'			=> (bool)$propertyValue_info['value']
								);
								break;
							case '3':
								$propertyForExport = array(
									'property_id'	=> $propertyID,
//									'propertyType'	=> 'LIST_ONE',
									'value_id'		=> (int)$propertyValue_info['value'],
								);
								if(!empty($propertyValue_info['factor'])){
									$propertyForExport['count'] = (int)$propertyValue_info['factor'];
								}
								break;
							case '4':
								$value['value_id'] = (int)$propertyValue_info['value'];
								if(!empty($propertyValue_info['factor'])){
									$value['count'] = (int)$propertyValue_info['factor'];
								}
								$values[] = $value;
								break;
							case '5':
								$propertyForExport = array(
									'property_id'	=> $propertyID,
//									'propertyType'	=> 'STRING',
									'value'		=> (string)$propertyValue_info['value']
								);
								break;
							case '6':
								$propertyForExport = array(
									'property_id'	=> $propertyID,
//									'propertyType'	=> 'RANGE',
									'value'			=> $propertyValue_info['value'].' - '.$propertyValue_info['factor']
								);
								break;
						}
					}
					if(!empty($values)){
						$propertyForExport = array(
							'property_id'	=> $propertyID,
//							'propertyType'	=> 'LIST_PLURAL',
							'values'		=> $values
						);
					}
					$propertiesForExport[] = $propertyForExport;
				}
			}
		}
		return $propertiesForExport;
	}
	public function getImages_forExport($goodID){
		$photoLinks = array();
		if(!is_numeric($goodID)){
			return $photoLinks;
		}
		$photoLinks = IMAGES::goodId($goodID)->getPhoto(IMAGES::type_FULLSIZE);
		if(count($photoLinks) > 0){
			foreach($photoLinks as &$photoUrl){
				$photoUrl = SITE_URL.$photoUrl;
			}
		}
		return $photoLinks;
	}
	public function getBrief($group_id, $properties){
		$brief = $this->_getShablonAnons($group_id);
		if(preg_match_all('#\[([^\]]+)\]#imsU', $brief, $shablonParts)){
			foreach($shablonParts[1] as &$shablonPart){
				if(preg_match_all('#\{([\d]+)\}#imsU', $shablonPart, $shablonPart_properties)){
					foreach($shablonPart_properties[1] as $propertyID){
						$anonsParts[] = $propertyID;
					}
				}
			}
			$brief = implode(',', $anonsParts);
		}
		return $brief;
	}
	public function propertyValue($property_id){
		$propertyValues = array();
		if(is_numeric($property_id)){
			$this->_loadProperties();
			foreach($this->_lastGoodsIDsList as $goodID){
				if(isset($this->_goods[$goodID]['properties'][$property_id])){
					$propertyValues[$goodID] = $this->_goods[$goodID]['properties'][$property_id];
				}
			}
		}
		return $propertyValues;
	}
	protected function _getShablonAnons($group_id){
		$shablonAnons = '';
		if(is_numeric($group_id)){
			$querySelect_groupAnons = '
				SELECT
					`parent`,
					`shablon_anons`
				FROM
					`art_shop_shop_grps`
				WHERE
					`id`='.$group_id.'
			';
			if($querySelect_groupAnons_result = DB::mysqli()->query($querySelect_groupAnons)){
				$row = $querySelect_groupAnons_result->fetch_assoc();
				if(empty($row['shablon_anons'])){
					$shablonAnons = $this->_getShablonAnons($row['parent']);
				}
				else{
					$shablonAnons = $row['shablon_anons'];
				}
			}
			else{
				// TODO: обработчик ошибок
			}
		}
		return $shablonAnons;
	}
	public static function Update($goods = array()){
		return self::_getInstance()->_update($goods);
	}
	protected function _update($goods = array()){
		$this->_load(array_keys($goods));
		foreach($this->_lastGoodsIDsList as $goodID){
			$this->_updateDB($goods[$goodID]);
		}
	}
	protected function _load($goodsIDs = array()){
		// обнуляем список последних СУЩЕСТВУЮЩИХ (из последних запрошенных) айдишников
		$this->_lastGoodsIDsList = array();
		// причёсываем в массив
		if(!is_array($goodsIDs)){
			$goodsIDs = array($goodsIDs);
		}
		if(count($goodsIDs) > 0){
			// проверяем корректность значений ID
			$cleanGoodsIDs = array();
			foreach($goodsIDs as $goodID){
				if(is_numeric($goodID)){
					$cleanGoodsIDs[] = $goodID;
				}
			}
			// проверяем не были ли уже загружены данные товары
			$goodsIDs_notLoaded = array_diff($cleanGoodsIDs, array_keys($this->_goods));
			// вытаскиеваем значения из кэша
			if(count($goodsIDs_notLoaded) > 0){
				$goodsIDs_unfindedInCache = $this->_getFromCache($goodsIDs_notLoaded);
				// загружаем из базы товары не найденные в кэше
				if(count($goodsIDs_unfindedInCache) > 0){
					$this->_getFromDB($goodsIDs_unfindedInCache);
				}
			}
			// fix-строка (может задвоится добавление из $this->_getFromDB)
			$this->_lastGoodsIDsList = array();
			// список текущих id-товаров
			foreach($cleanGoodsIDs as $loadedGoodID){
				if(isset($this->_goods[$loadedGoodID])){
					$this->_lastGoodsIDsList[] = $loadedGoodID;
				}
			}
			$this->currentCount = count($this->_lastGoodsIDsList);
		}
		return $this;
	}
	protected function _getFromCache($goodsIDs = array()){
//		if($cacheGoods = CACHE::memcache()->get(array_map(array(__CLASS__, '_cacheName_prefixAdd'), $goodsIDs))){
//			foreach($cacheGoods as $cacheGood){
//				$this->_take($cacheGood);
//			}
//		}
		// вычеслить какие ID - не нашли и вернуть их массивом
		// ...
		return $goodsIDs;
	}
	protected function _getFromDB($goodsIDs = array()){
		if($loadGoods = DB::mysqli('SELECT * FROM `art_shop_shop_goods` WHERE `id` IN ('.implode(',', $goodsIDs).')')){
			// сохраняем в кэш
			$this->_saveToCache($loadGoods);
			// сохраняем в рабочий массив
			$this->_take($loadGoods);
		}
	}
	protected function _take($goods){
		foreach($goods as $good){
			$this->_goods[$good['id']] = $good;
			// записываем в список последних СУЩЕСТВУЮЩИХ (из последних запрошенных) айдишников
			$this->_lastGoodsIDsList[] = $good['id'];
		}
		$this->currentCount = count($this->_lastGoodsIDsList);
	}
	protected function _saveToCache($goods = array()){
//		foreach($goods as $good){
//			CACHE::memcache()->set('good_'.$good['id'], $good);
//		}
		return TRUE;
	}
	protected function _updateDB($goodProperties = array()){
		// забираем айдишник товара
		$goodID = $goodProperties['id'];
		unset($goodProperties['id']);
//		$updatedProperties = $goodProperties;
		// подготавливаем строку SET для UPDATE-запроса
		array_walk(
			$goodProperties,
			function(&$value, $key){
				if(is_numeric($value) || $value == 'NULL'){
					$value = '`'.$key.'`='.DB::mysqli()->real_escape_string($value);
				}
				elseif(is_null($value)){
					$value = '`'.$key.'`=NULL';
				}
				else{
					$value = '`'.$key.'`=\''.DB::mysqli()->real_escape_string($value).'\'';
				}
			}
		);
		// формируем запрос
		$queryUpdate = '
			UPDATE
				`'.self::TABLENAME.'`
			SET
				'.implode(', ', $goodProperties).'
			WHERE
				`id`='.$goodID.'
		';
		if(DB::mysqli()->query($queryUpdate)){
//			if(isset($this->_goods[$goodID])){
//				foreach($updatedProperties as $updateProperty=>$updateValue){
//					if(isset($this->_goods[$goodID][$updateProperty])){
//						$this->_goods[$goodID][$updateProperty] = $updateValue;
//					}
//				}
//			}
			return TRUE;
		}
		else{
			exit('<span style="color:red;">ERROR</span> :: '.__FILE__.' :: '.__LINE__.'<br />'.DB::mysqli()->error.'<br />'.$queryUpdate);
		}
//		$this->_saveToCache($good);
	}
	public function getFields($fields = array(), $resultAsList = FALSE){
		$resultGoods = array();
		// проверка массив - не массив
		if(!is_array($fields)){
			$fields = array($fields);
		}
		// отдаём значения
		foreach($this->_lastGoodsIDsList as $requestGoodID){
			if(!empty($this->_goods[$requestGoodID])){
				foreach($fields as $fieldName){
					if(isset($this->_goods[$requestGoodID][$fieldName])){
						if($resultAsList){
							$resultGoods[] = trim($this->_goods[$requestGoodID][$fieldName]);
						}
						else{
							$resultGoods[$requestGoodID][$fieldName] = trim($this->_goods[$requestGoodID][$fieldName]);
						}
					}
				}
			}
		}
		return $resultGoods;
	}
	public function getMass(){
		$itemMass = array();
		foreach($this->_lastGoodsIDsList as $itemId){
			$itemMass[$itemId] = 'н/д';
		}
		$query = "
			SELECT
				`items`.`id`,
				`grps`.`id` as `group_id`,
				`parent`.`id` as `parent_id`,
				`level0`.`id` as `level0_id`,
				`items`.`mass`,
				`grps`.`default_mass` as `group_mass`,
				`parent`.`default_mass` as `parent_mass`,
				`level0`.`default_mass` as `level0_mass`,
    			`root`.`default_mass` as `root_mass`
			FROM
				`art_shop_shop_goods` as `items`
				LEFT JOIN
					`art_shop_shop_grps` as `grps`
				ON
					`items`.`grpid`=`grps`.`id`
					LEFT JOIN
						`art_shop_shop_grps` as `parent`
					ON
						`items`.`grpid0`=`parent`.`id`
						LEFT JOIN
							`art_shop_shop_grps` as `level0`
						ON
							`parent`.`parent`=`level0`.`id`
                            LEFT JOIN
                                `art_shop_shop_grps` as `root`
                            ON
                                `level0`.`parent`=`root`.`id`
			WHERE
				`items`.`id` IN (".implode(',', $this->_lastGoodsIDsList).")
			ORDER BY
				`items`.`id` ASC
			;
		";
		if($selectMassInfo = DB::mysqli()->query($query)){
			while($item = $selectMassInfo->fetch_assoc()){
				$mass = FALSE;
//				$massIsAVG = FALSE;
				// выбираем значение
				if(!empty($item['mass'])){
					$mass = $item['mass'];
				}
				elseif(!empty($item['group_mass'])){
					$mass = $item['group_mass'];
				}
				elseif(!empty($item['parent_mass'])){
					$mass = $item['parent_mass'];
				}
				elseif(!empty($item['level0_mass'])){
					$mass = $item['level0_mass'];
				}
				elseif(!empty($item['root_mass'])){
					$mass = $item['root_mass'];
				}
				// добавляем значение в результирующий массив
				if($mass){
					$itemMass[$item['id']] = (ceil($mass*10)/10);
				}
			}
		}
		return $itemMass;
	}
	public function cacheName_prefixAdd($goodID){
		return 'good_'.$goodID;
	}

	// ===========================
	// составление запроса c сортировка по харе
	// ===========================
    /**
	 *
     * @param int[] $groups_ids			- айди группы с правилами сортировки,<p>- либо массив из айди групп из которых необходима выборка (при условии $queryWhere = FALSE), при этом посик хары для сортировки пока происходит только в первой группе</p>
     * @param string $queryWhere		[optional]<p>условия выборки товаров для сортировки</p>
     * @param int $goodsOnPage_limit	[optional]<p>количество товаров на странице</p>
     * @param int $pageNumber			[optional]<p>запрашиваемая страница</p>
     * @param bool $showFlag_check		[optional]<p>проверка флага show_flag для товаров (при условии $queryWhere = FALSE)</p>
     * @param bool $use_sortongCookie	[optional]<p>использовать порядок сортировки заданный пользователем при просмотре сайте</p>
     *
     * @return array(bool,string)		массив содержит два элемента:<p>- флаг наличия в группе хары для сортировки</p><p>- запрос для MySQL c необходимой сортировкой</p>
     */
	public static function getQuery_sortByHara($groups_ids, $queryWhere = FALSE, $goodsOnPage_limit = FALSE, $pageNumber = 1, $showFlag_check = TRUE, $use_sortongCookie = TRUE){
		// направление сортировки
		$tableName = 'art_shop_shop_goods';
		$sortField = $tableName . '`.`title';
		$sortType = 'ASC';
		$sortByName = 'ASC';
		$queryJoin = '';
		if($use_sortongCookie && (!empty($_COOKIE['sorting']))){
			switch($_COOKIE['sorting']){
				case 'popularity_asc':
					ORDERSTAT::set_interval(date("Y-m-d",time()-86400*30), date("Y-m-d"))->createPopularityTable();
					$queryJoin = 'LEFT JOIN `goods_popularity` ON (`id` = `goods_popularity`.`goods_id`)';
					$sortField = 'goods_popularity`.`count';
					$sortType = 'DESC';
					break;
				case 'popularity_desc':
					ORDERSTAT::set_interval(date("Y-m-d",time()-86400*30), date("Y-m-d"))->createPopularityTable();
					$queryJoin = 'LEFT JOIN `goods_popularity` ON (`id` = `goods_popularity`.`goods_id`)';
					$sortField = 'goods_popularity`.`count';
					$sortType = 'ASC';
					break;
				case 'price_asc':
					$sortField = $tableName . '`.`price';
					$sortType = 'ASC';
					break;
				case 'price_desc':
					$sortField = $tableName . '`.`price';
					$sortType = 'DESC';
					break;
				case 'name_desc':
				case 'hara_desc':
					$sortField = $tableName . '`.`title';
					$sortType = 'DESC';
					$sortByName = 'DESC';
					break;
				case 'name_asc':
				case 'hara_asc':
				default:
					$sortField = $tableName . '`.`title';
					$sortType = 'ASC';
					break;
			}
		}
                
		// определение группы
		$resultQuery = FALSE;
		$groupsIDs = array();

		$sortByHara = FALSE;
		if(!is_array($groups_ids)){
			$groupsIDs[] = $groups_ids;
		}
		else{
			$groupsIDs = $groups_ids;
		}
		$groupsIDs_childs = array();
		foreach($groupsIDs as $i=>$groupID){
			if(!is_numeric($groupID)){
				unset($groupsIDs[$i]);
			}
			else{
				$groupsIDs_childs = array_merge($groupsIDs_childs, GROUPS::getChilds($groupID, TRUE));
			}
		}
		// сливаем запрошенный массив айдишников с дочерними айдишниками групп
		$groupsIDs_childs = array_unique($groupsIDs_childs);
		$groupsIDs = array_merge($groupsIDs, $groupsIDs_childs);
		$groupsIDs = array_unique($groupsIDs);
		// определение хары
		if(!empty($groupsIDs)){
                    	// TODO: для массива групп - проверка не только первой, а всех
			// сортировка: отсортировать каждую группу согласно своей харе, а сами группы отсортировать по названию
			$groupID_main = (!empty($groupsIDs[0])) ? $groupsIDs[0] : '-1';
                        
                        // Если запрашиваемая группа уже содержит указание по какой хар-ке сортировать - не надо искать родителей
						$info = GROUPS::ID($groupID_main)->getInfo();
                        if ( isset($info['sort_by_hara']) && $info['sort_by_hara'] > 0 ) {
                            $parents[0] = $groupID_main;
                        }
                        else {
                            // у запрашиваемой группы нет значения по какой хар-ке сортировать - ищем у родителей
                            $parents = GROUPS::getParent($groupID_main, true); // родителей получаем с флагом $responseIDS = true, чтобы получить коды
                        }
                        
                        $query_groupSelect = DB::mysqli()->query("
				SELECT `sort_by_hara` AS `sortBy_hara`
				FROM `art_shop_shop_grps` as `grp`
				WHERE `grp`.`id` IN (" . implode(', ', $parents) . ") AND `sort_by_hara` > 0 LIMIT 1");
                        if($query_groupSelect){
				while($row = $query_groupSelect->fetch_assoc()){
                                    	if(!empty($row['sortBy_hara'])){
                                            	$query_haraSelect = DB::mysqli()->query('
							SELECT
								`id`,
								`tip`
							FROM
								`art_shop_shop_param3`
							WHERE
								`id`='.$row['sortBy_hara'].'
						');
						if($query_haraSelect){
							while($row = $query_haraSelect->fetch_assoc()){
								$sortByHara = $row;
							}
						}
					}
				}
			}
		}
		// сколько товаров отобразить
		if($pageNumber < 1) $pageNumber = 1;
		$queryLimit = ($goodsOnPage_limit) ? 'LIMIT '.(($pageNumber - 1) * $goodsOnPage_limit).', '.$goodsOnPage_limit : '';
		// основные условия выборки
		if(empty($queryWhere)){
			$queryWhere = '
				`present_flag` > 0
				'.(($showFlag_check) ? 'AND `show_flag` > 0' : '').'
				AND	`grpid` IN ('.implode(',', $groupsIDs).')
			';
		}
        if(!empty($_SESSION['user_login0']) && isset($_COOKIE['only_stock']) && $_COOKIE['only_stock'] == 1){
            $queryWhere .= ' AND (in_stock > 0 OR near_transit > 0)';
        }
		// формируем запрос
		if(empty($sortByHara)){
                    	// запрос с сортировкой по $sortField - при отсутствии в группе "сортировочной" хары + TRIM, в случае title (для удаления начальных пробелов)
			$orderBy = ($sortField != $tableName . '`.`title') ? '`'.$sortField.'` '.$sortType.',' : '';
			$resultQuery = '
				SELECT
					*
				FROM
					`'.$tableName.'`
					' . $queryJoin . '
				WHERE
					'.$queryWhere.'
				ORDER BY
					'.$orderBy.'
					TRIM(`title`) ' . $sortByName . '
				'.$queryLimit.'
			';
		}
		else{
			// запрос с сортировкой по харе
			$querySelect = '';
//			$queryJoin = '';
			// при сортировке не по названию/харе - ставим указанное поле первым
			$querySort = ($sortField != $tableName . '`.`title') ? "`$sortField` $sortType," : '';
			$lastValues_ord = '999999999';
			$lastValues = 'zzzzzzzzz';
			$sortType = strtoupper($sortType);
			switch($sortType){
				case 'ASC':		$lastValues_ord = '999999999';	$lastValues = 'zzzzzzzzz';						break;
				case 'DESC':	$lastValues_ord = $lastValues = '0';											break;
				default:		$lastValues_ord = '999999999';	$lastValues = 'zzzzzzzzz';	$sortType = 'ASC';	break;
			}
			switch($sortByHara['tip']){
				case '1':
				case '2':
				case '5':
				case '6':
					$querySort .= "
							`sortValue` $sortType,
					";
					break;
				case '3':
				case '4':
					$querySelect .= '
						IFNULL(`art_shop_shop_par_uncal3`.`ord`, '.$lastValues_ord.')+0 as `sortValue_ord`,
						IFNULL(`art_shop_shop_par_uncal3`.`value`, \''.$lastValues.'\') as `sortValue_value`,
					';
					$queryJoin .= '
						LEFT JOIN
							`art_shop_shop_par_uncal3`
							ON
							`art_shop_shop_par_uncal3`.`id` = `art_shop_property_goods`.`value`
					';
					$querySort .= "
							`sortValue_ord` $sortType,
							`sortValue_value` $sortType,
					";
					break;
			}
			// запрос
			$resultQuery = "
				SELECT
				$querySelect
				CONVERT(IFNULL(`art_shop_property_goods`.`value`, $lastValues_ord), DECIMAL(10,2)) as `sortValue`,
				`$tableName`.*
				FROM
					`$tableName`
					LEFT JOIN
						`art_shop_property_goods`
					ON(
						`art_shop_property_goods`.`good_id` = `$tableName`.`id`
						AND
						`art_shop_property_goods`.`propy_id` = $sortByHara[id]
					)
					$queryJoin
				WHERE
					$queryWhere
				GROUP BY
					`$tableName`.`id`
				ORDER BY
					$querySort
					TRIM(`$tableName`.`title`) $sortByName
				$queryLimit
			";
		}
                
		return array((empty($sortByHara) ? FALSE : TRUE), $resultQuery);
	}
	
	protected function _loadProperties(){
		$querySelect_goodProperties = '
			SELECT
				*
			FROM
				`'.self::TABLENAME_PROPERTIES.'`
			WHERE
				`good_id` IN ('.implode(',', $this->_lastGoodsIDsList).')
		';
		if($querySelect_goodProperties_result = DB::mysqli()->query($querySelect_goodProperties)){
			while($row = $querySelect_goodProperties_result->fetch_assoc()){
				$this->_goods[$row['good_id']]['properties'][$row['propy_id']][] = $row['value'];
			}
		}
	}
	
	public function recalc_deliveryPrice($delivery_price, $expression_hara, $expression_operand, $expression_compareValue, $expression_price){
		if($delivery_price == 0) {
			// Если каким-то образом передан 0 в качестве базовой стоимости, то берем значение по умолчанию
			$delivery_price = SETTINGS::Get('deliveryPrice_base');
		}
		$goods_deliveryPrices = array();
		if($this->_lastGoodsIDsList > 0){
			$deliveryPrice_byExpression = array();
			$this->_loadProperties();
			// рассчёт по весу
			$deliveryPrice_byMass = $this->getMass();
			array_walk(
				$deliveryPrice_byMass,
				function(&$goodMass, $goodID){
					// Если вес не равен 0, считаем стоимость, иначе ниже возьмём дефолт для группы
					$goodMass = $goodMass == 0 ? 0 : deliveryPrice_calculate($goodMass, 0);
				}
			);
			// смотрим базовую стоимость для группы (в которую входит данный товар)
			foreach($this->_lastGoodsIDsList as $value) {
				// Стоимость доставки по весу приоритетнее базовой. Так что если указан вес, то берём стоимость по нему.
				$deliveryPrice_byExpression[$value] = $deliveryPrice_byMass[$value] == 0 ? $delivery_price : $deliveryPrice_byMass[$value];
			}
			// рассчёт по условию
			foreach($this->_lastGoodsIDsList as $goodID){
				if(isset($this->_goods[$goodID]['properties'][$expression_hara])){
					if(PROPERTIES::IDs($expression_hara)->matchExpression($this->_goods[$goodID]['properties'][$expression_hara], $expression_operand, $expression_compareValue)){
						$deliveryPrice_byExpression[$goodID] = $expression_price;
					}
				}
			}
			// выбираем максимальную цену по каждому товару
			foreach($this->_lastGoodsIDsList as $goodID){
				$this->_goods[$goodID]['deliveryPrice'] = ($deliveryPrice_byExpression[$goodID] > $deliveryPrice_byMass[$goodID]) ? $deliveryPrice_byExpression[$goodID] : $deliveryPrice_byMass[$goodID];
				$this->_goods[$goodID]['deliveryPrice_case'] = ($deliveryPrice_byExpression[$goodID] > $deliveryPrice_byMass[$goodID]) ? 'hara' : 'mass';
				// сохраняем
				$updateGood_newInfo = array(
					'id'					=> $goodID,
					'deliveryPrice'			=> $this->_goods[$goodID]['deliveryPrice'],
					'deliveryPrice_case'	=> $this->_goods[$goodID]['deliveryPrice_case']
				);
				// update-им в базе
				$this->_updateDB($updateGood_newInfo);
				// добавляем в результирующий массив - чтобы сообщить о сделанных правках
				$goods_deliveryPrices[$goodID] = $this->_goods[$goodID]['deliveryPrice'];
			}
		}
		return $goods_deliveryPrices;
	}
}


