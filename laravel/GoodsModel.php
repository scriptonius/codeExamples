<?php
namespace App\Models;

use App\FrontFormat;
use App\Models\Characteristics\CharacteristicsModel;
use App\Models\Characteristics\CharacteristicsValuesModel;
use Illuminate\Support\Facades\Log;

class GoodsModel extends FrontFormat
{
    protected $table = 'art_shop_shop_goods';
    protected $perPage = 50;
    protected $hidden = ['price_old', 'date'];
    protected $visible = [
        'id',
        'grpid',
        'grpid0',
        'title',
        'sm_title',
        'price',
        'deliveryPrice',
        'deliveryPrice_case',
        'origname',
        'vendor',
        'vendorcode',
        'yaphraza',
        'market_id',
        'filled_full',
        'filled_characteristics'
    ];
    protected $fillable = [
        'title',
        'sm_text',
        'txt',
        'price',
        'filled_full',
        'filled_characteristics',
        'deliveryPrice',
        'deliveryPrice_case'
    ];
    protected $appends = [
        'photos',               // массив изображений
        'photo','photo_1',
        'deliveryPriceValue',   // значение той хар-ки, от которой зависит цена доставки
        'isDeliveryFree',       // рассчетное значение, что товар с бесплатной доставкой
        'isDeliveryPriceBase',  // стоимость доставки совпадает с "общей" для группы или из настроек
    ];

    public function getPhotosAttribute() {
        $images = new ImagesModel($this);
        $size = $this->photo_sizes[ImagesModel::type_PREVIEWLIST];
        $photo = $images->findPhoto(true, $size);
        return $photo ?: [];
    }

    public function getPhotoAttribute() {
        $images = new ImagesModel($this);
        $images->setCurrentNum(null);
        $photo = $images->getPhoto(ImagesModel::type_PREVIEWLIST);
        return $photo ?: null;
    }

    public function getPhoto1Attribute() {
        $images = new ImagesModel($this);
        $images->setCurrentNum(1);
        $photo = $images->getPhoto(ImagesModel::type_PREVIEWLIST);
        return $photo ?: null;
    }

    protected $isDeliveryFree;

    public $timestamps = false;

    protected $_use_mass_size_for_filled = false; // Учитывать флаги "Учитывать размеры/вес" при расчете заполненности описания

    public $errors_filled = [];             // Ошибки полного описания: что не указано у товара, чтобы считать его заполненным
    public $errors_characteristics = [];    // Какие обязательные хар-ки не заполненны

    public $photo_sizes = array(
        ImagesModel::type_ORIGINAL => [1000],
        ImagesModel::type_PREVIEWLIST => [53]
    );

    public $downloadable_files = [];
    public $downloadable_images = ['photos','photo','photo_1'];
    public $deleted_files = [];

    protected $_historyLog_tableName = 'goods__history';
    protected $_historyLog_foreignKey = 'good_id';
    protected $_historyLog_employeeField = 'user_id';
    protected $_historyLog_employeeIdentity = 'name';
    protected $_historyLog_createdField = 'kogda';

    public function __construct(array $attributes = [])
    {
        // Путь к субдиректорию с фото ДО ВЫЗОВА ПАРЕНТ!
        $this->setPhotoPath(ImagesModel::SHOP_PHOTO_DIR);
        parent::__construct($attributes);
        $this->append(['DT_RowId', 'sort_value']);
    }

    public static function boot()
    {
        /**
         * После сохранения модели сохраняем фотки
         * after save event handler
         * FrontFormat::saveImages
         */
        self::saving([__CLASS__, 'saveImages']);
        parent::boot();
    }

    /**
     * @param mixed $photo_path
     */
    public function setFullPhotoPath($photo_path)
    {
        $this->full_photo_path = $photo_path;
    }

    /**
     * @param mixed $preview_path
     */
    public function setFullPreviewPath($preview_path)
    {
        $this->full_preview_path = $preview_path;
    }

    public function warranty()
    {
        return $this->belongsToMany('App\Models\WarrantyModel', 'warranty_goods', 'warranty_id', 'good_id');
    }

    public function offers() {
        return $this->belongsToMany('App\Models\OffersModel', 'offers__goods', 'good_id', 'offer_id');
    }

    public function group() {
        return $this->belongsTo('App\Models\GroupsModel', 'grpid', 'id');
    }

    public function rootGroup() {
        return $this->belongsTo('App\Models\GroupsModel', 'grpid0', 'id');
    }

    public function vendorDetails() {
        return $this->hasMany('App\Models\VendorsModel', 'name', 'vendor');
    }

    public function characteristics() {
        return $this
            ->belongsToMany('App\Models\Characteristics\CharacteristicsModel', 'art_shop_property_goods', 'good_id', 'propy_id')
            ->withPivot('value', 'factor');
    }

    public function YmRivals() {
        return $this->belongsTo('\App\Models\Grabber\YmRivalsModel', 'market_id', 'model_id');
    }


    public function getSortOrdAttribute() {
        $property = $this->getGroupSort();
        return $property->ord ?? NULL;
    }

    public function getSortValueAttribute() {
        $property = $this->getGroupSort();
        return $property->value ?? 999999999;
    }

    public function getTxtAttribute($value) {
        return htmlentities($value, ENT_COMPAT, 'UTF-8');
    }

    public function getKogdavvelAttribute($value) {
        $date = $value;
        if(empty($date)) {
            $date = date('Y-m-d', 0);
        }
        return $date;
    }

    public function getKogdacontentAttribute($value) {
        $date = $value;
        if(empty($date)) {
            $date = date('Y-m-d ', 0);
        } else {
            if(strtotime($date) < 0) {
                $date = date('Y-m-d', 0);
            }
        }
        return $date;
    }

    public function getDeliveryPriceValueAttribute() {
        return $this->deliveryPrice_value();
    }

    public function getIsDeliveryFreeAttribute() {
        return $this->isDeliveryFree();
    }

    public function getIsDeliveryPriceBaseAttribute() {
        $value = GroupsModel::findOrFail($this->grpid)->deliveryInfo();
        if ($value == $this->deliveryPrice) {
            return true;
        }
        return false;
    }

    public function hasPhoto() {
        $image = new ImagesModel($this);
        $image->useCache(false);
        if ( $image->getPhoto(ImagesModel::type_ORIGINAL) ) {
            return true;
        }
        return false;
    }

    /*
     * Получить данные о заполнености характеристик
     *
     */
    public function getFilledCharacteristics() {

        $grpid = null;

        // Сначала пробуем получить хар-ки напрямую, по связи с grpid или grpid0
        $characterics  = $this->hasMany('App\Models\Characteristics\CharacteristicsModel', 'grpid', 'grpid')->get();
        if ( count($characterics) > 0 ) {
            $grpid = $this->grpid;
        }
        else {
            $characterics = $this->hasMany('App\Models\Characteristics\CharacteristicsModel', 'grpid', 'grpid0')->get();
            if ( count($characterics) > 0 ) {
                $grpid = $this->grpid0;
            }
            else {
                // Если для товара нет хар-к, сопоставленных по grpid/grpid0 - найдем все дерево до корня
                $characterics = Characteristics\CharacteristicsModel::whereIn('grpid', GroupsModel::parentsGroups($this->grpid))->get();
            }
        }

        $characterics_needed_ids = array();     // массив id хар-к, необходимых для заполнения
        $characterics_needed_types = array();   // масcив ид => тип
        $characterics_needed_names = array();   // массив ид => наименование хар-ки
        foreach($characterics AS $characteric) {
            if ($characteric->obligatory <= 0) {
                continue; // не обязательная для заполнения
            }
            if ($characteric->tip == 0) {
                continue; // эта группа - раздел, включающий другие группы. не заполняется.
            }
            if ($characteric->attributes['visible'] == 0) {
                continue; // не видимая группа
            }
            $characterics_needed_ids[] = $characteric->id;
            $characterics_needed_types[$characteric->id] = $characteric->tip;
            $characterics_needed_names[$characteric->id] = $characteric->name;

        }

        // если нет обязательных хар-к
        if ( count($characterics_needed_ids) <= 0 ) {
            return true;
        }

        // Проверяем, что заполнены все обязательные хар-ки
        $characterics_used = array();
        $values = $this->hasMany('App\Models\Characteristics\CharacteristicsValuesModel', 'good_id', 'id')->get();
        foreach($values AS $value) {
            $propy_id = $value->propy_id;

            // необязательная
            if ( !in_array($propy_id, $characterics_needed_ids) ) {
                continue;
            }

            // пустое значение - не считаем заполненным. не использовать empty() !
            if ( $value->value == "" ) {
                continue;
            }

            // у типов-селектов значение "0" - не считается заполненным
//            if ( ($characterics_needed_types[$propy_id] == 3 || $characterics_needed_types[$propy_id] == 4) && $value->value == '0' ) {
//                continue;
//            }

            $characterics_used[$propy_id] = $value->value;
        }

        // запомним, какие хар-ки не заполнены
        $diff = array_diff_key(array_flip($characterics_needed_ids), $characterics_used);
        foreach($diff AS $char_key => $char_value) {
            $this->errors_characteristics[] = $characterics_needed_names[$char_key];
        }

        // кол-во обязательных хар-к = равняется кол-ву заполненных обязательных хар-к
        if ( count($characterics_used) == count($characterics_needed_ids) ) {
            return true;
        }

        return false;
    }

    /*
     * Обновить значение заполненности хар-к
     */
    public function refreshFilledCharacteristics(){
        $flag = 0;
        if ($this->getFilledCharacteristics()) {
            $flag = 1;
        }
        $this->update(["filled_characteristics" => $flag]);
        return $flag;
    }

    /*
     * Получить статус полной заполненности товара, на основании хар-к, фото, веса/размера
     */
    public function getFilledFull() {
        $group_use_size = true;     // настройка "использовать размеры" у групп товара
        $group_use_mass = true;     // настройка "использовать вес" у групп товара

        $flag_use_size = false;     // размеры не заполнены
        $flag_use_mass = false;     // вес не заполнен
        $flag_photo    = false;     // фото
        $flag_chars    = false;     // обязательные хар-ки

        // Сразу - если стоит флаг принудительно считать заполненным
        if ( $this->flag_zapolnen ) {
            return true;
        }

        $groups = GroupsModel::parentsGroups($this->grpid); // группы товара, от текущей группы до корневой

        // при расчете нужно обратить внимание на массу/размер
        if ($this->_use_mass_size_for_filled) {

            // Значения флагов использования размеров/веса работают по следующему правилу:
            //  - чем выше группа (ближе к корневой) в иерархии групп - тем приоритетнее ее значения
            //    ... даже так: если в дереве групп есть значение "Нет" у этих флагов = это значение и будет использоваться
            // TODO: При переходе на новую админку - поправить эту часть
            //
            foreach($groups AS $group) {
                if ( ! $group->use_size ) {
                    $group_use_size = false;
                    break;
                }
            }
            foreach($groups AS $group) {
                if ( ! $group->use_mass ) {
                    $group_use_mass = false;
                    break;
                }
            }

            // Если учитывать размеры - да, и размеры - указаны
            if ( $group_use_size && ($this->width > 0 && $this->height > 0 && $this->depth > 0) ) {
                $flag_use_size = true;
            }
            else {
                $this->errors_filled[] = 'Не указаны размеры';
            }
            // Если учитывать вес - да, и вес - указан
            if ( $group_use_mass && $this->mass > 0 ) {
                $flag_use_mass = true;
            }
            else {
                $this->errors_filled[] = 'Не указан вес';
            }

        }
        else {
            $flag_use_mass = true;
            $flag_use_size = true;
        }


        // Если есть фото
        if ( $this->hasPhoto() ) {
            $flag_photo = true;
        }
        else {
            $this->errors_filled[] = 'Нет фото';
        }

        // Если заполнены обязательные хар-ки
        if ( $this->getFilledCharacteristics() ) {
            $flag_chars = true;
        }
        else {
            $this->errors_filled[] = 'Не заполнены обязательные характеристики';
        }


        if ( $flag_chars && $flag_photo && $flag_use_mass && $flag_use_size ) {
            return true;
        }

        return false;

    }

    public function refreshFilledFull() {
        $flag = 0;
        if ($this->getFilledFull()) {
            $flag = 1;
        }
        $this->update(["filled_full" => $flag]);
        return $flag;
    }

    protected function getGroupSort() {
        if((int) $this->grpid === 0) {
            return NULL;
        }
        $sort = GroupsModel::findOrFail($this->grpid)->getSort();
        $item = $this->with('characteristics')->findOrFail($this->id);
        $characteristics = $item->characteristics;
        foreach ($characteristics as $characteristic) {
            if($characteristic->id == $sort) {
                return $characteristic;
            }
        }
        return NULL;
    }

    /*
     * Возвращает краткое описание товара на основании шаблона
     *
     * @param integer $id ИД товара
     *
     * @return string
     */
    public static function getDescription( $id ) {
        $goods = self::findOrFail($id);

        // У товара есть ручное краткое описание - вернем его.
        // TODO: на сайте сейчас не используется краткое ручное (например 27797). Уточнить почему?
        if ( !empty($goods->sm_text) ) {
            return $goods->sm_text;
        }

        // шаблон, в который надо вставить значения параметров
        $template_source = GroupsModel::getTemplateDescription($goods->grpid);

        // Получаем массив всех хар-к, значения которых должны быть вставлены в шаблон краткого анонса
        $characteristic_source = [];
        preg_match_all("|{(.*)}|iU", $template_source, $characteristic_source);
        if ( count($characteristic_source[0]) <= 0 ) {
            return '';
        }

        // Получим значения хар-к и приведем к виду $['id_хар-ки'] = Значение
        $characteristic_values = [];
        $characteristic_values_source = Characteristics\CharacteristicsValuesModel
                                        ::where('good_id', '=', $id)
                                        ->whereIn('propy_id', $characteristic_source[1])
                                        ->get();
        foreach ($characteristic_values_source AS $characteristic_value) {
            // тип
            $characteristic_type = Characteristics\CharacteristicsModel::find($characteristic_value->propy_id);
            switch ($characteristic_type->tip) {
                case 1:     // число
                    $characteristic_values[$characteristic_value->propy_id] = $characteristic_value->value;
                    break;
                case 2:     // логическое значение
                    if ($characteristic_value->value == 1) {
                        $characteristic_values[$characteristic_value->propy_id] = $characteristic_type->name;
                        if ( ! empty($characteristic_type->sinonim) ) {
                            $characteristic_values[$characteristic_value->propy_id] = $characteristic_type->sinonim;
                        }
                    }
                    break;
                case 3:     // одно значение
                    $characteristic_info = Characteristics\CharacteristicsVariablesModel::find($characteristic_value->value);
                    $characteristic_values[$characteristic_value->propy_id] = $characteristic_info->value;
                    if ( ! empty($characteristic_info->sinonim) ) {
                        $characteristic_values[$characteristic_value->propy_id] = $characteristic_info->sinonim;
                    }
                    break;
                case 4:     // множество значений. Сделаем их массивом.
                    $value4 = Characteristics\CharacteristicsVariablesModel::find($characteristic_value->value);
                    $value4_value = $value4->value;
                    $value4_sinonim = $value4->sinonim;
                    $characteristic_values[$characteristic_value->propy_id][] = ( !empty($value4_sinonim) ? $value4_sinonim : $value4_value);
                    break;
                case 5:     // Произвольный текст
                    break;
                case 6:     // Диапазон
                    if ( $characteristic_value->value > 0 ) {
                        $characteristic_values[$characteristic_value->propy_id] = $characteristic_value->value;
                    }
                    break;
            }

            // Постфиксы
            $_postfix = $characteristic_type->postfix;
            if (!empty($_postfix)) {
                $_postfix = str_replace("м2", "м<sup>2</sup>", $_postfix);
                $_postfix = str_replace("м3", "м<sup>3</sup>", $_postfix);
                $characteristic_values[$characteristic_value->propy_id] .= ' '.$_postfix;
            }

        }

        // теперь идем по шаблону-скелету, и вместо кодов подставляем полученные значения хар-к
        $template_result = $template_source;
        foreach($characteristic_source[1] AS $characteristic_id) {
            //dd($characteristic_source[1], $value);
            if ( isset($characteristic_values[$characteristic_id]) ) {
                $characteristic_value = $characteristic_values[$characteristic_id];
                if (is_array($characteristic_value) ) {
                    $characteristic_value = implode(", ", $characteristic_value);
                }
                $template_result = str_replace('{'.$characteristic_id.'}', $characteristic_value, $template_result);
            }
        }

        // Эта операция убирает части вида [x-x-x] при условии, что в квадратных скобках есть незаполненое значение
        $template_result = preg_replace('/(\[[^\[\]]*\{\d+\}[^\]\[]*\])/', '', $template_result);

        // В шаблоне остались {x}
        preg_match_all("|{(.*)}|iU", $template_result, $characteristic_empty);
        if ( count($characteristic_empty) > 0 ) {
            for($i=0; $i<count($characteristic_empty[0]); $i++) {
                $template_result = str_replace($characteristic_empty[0][$i], "", $template_result);
            }
        }

        // Перенос строки заменим на запятую
        $template_result = str_replace("\r\n", ", ", $template_result);
        $template_result = str_replace("\r", ", ", $template_result);
        $template_result = str_replace("\n", ", ", $template_result);

        $template_result = str_replace(" ,", "", $template_result);

        $template_result = str_replace("[", "", $template_result);
        $template_result = str_replace("]", "", $template_result);

        $template_result = trim($template_result, " \t\n\r\0\x0B,");

        return $template_result;// . '&nbsp;&nbsp;&nbsp;&nbsp;'.$template_source;
    }

    /*
     * Возвращает вес товара. При переносе текущего функционала обращаем внимание, что старая функция возвращала массив!
     *
     * @param integer $id ИД товара
     *
     * @return float
     */
    public static function getMass( $id ) {
        $goods = self::findOrFail($id);

        // Есть индивидуальный вес
        if ( $goods->mass > 0 ) {
            return $goods->mass;
        }

        // получим дерево групп у этого товара и возьмем первое не пустое значение массы
        $tree = GroupsModel::parentsGroups($goods->grpid);
        foreach($tree AS $group) {
            if ($group->default_mass > 0) {
                return $group->default_mass;
            }
        }

        return 0;
    }

    public function copyCharacteristics($source_id) {
        // Сам исходный (копируемый) товар
        $source_goods = GoodsModel::findOrFail($source_id);
        // Все хар-ки из копируемого товара
        $data = CharacteristicsValuesModel::where('good_id', $source_id)->get();

        $characteristics = [];
        foreach ($data as $characteristic) {
            $characteristics[] = [
                'good_id'   => $characteristic->good_id,
                'propy_id'  => $characteristic->propy_id,
                'value'     => $characteristic->value,
                'factor'    => $characteristic->factor
            ];
        }

        // У исходного для копирования товара нет хар-к
        if ( count($characteristics) <= 0 ) {
            return false;
        }

        // Очищаем все имеющиеся значения хар-к у конечного (текущего) товара
        CharacteristicsValuesModel::where('good_id', $this->id)->delete();

        // Копируем все хар-ки исходного (копируемого) товара для конечного (текущего) товара
        foreach($characteristics as $characteristic) {
            $ch = new CharacteristicsValuesModel();
            $ch->good_id = $this->id;
            $ch->propy_id = $characteristic['propy_id'];
            $ch->value = $characteristic['value'];
            $ch->factor = $characteristic['factor'];
            $ch->save();
        }

        // Копируем те данные, которые находятся непосредственно в таблице товаров
        $columns = ['sm_text', 'width', 'height', 'depth', 'mass']; // список полей для копирования
        foreach ($columns as $column) {
            $this->$column = $source_goods->$column;
        }
        $this->save();
    }

    public function copyPhotos($source_id) {
        // Сам копируемый товар
        $source_goods = GoodsModel::findOrFail($source_id);

        // Получаем все оригинальные фото исходного (копируемого) товара
        $images = new ImagesModel($source_goods);
        $images->useCache(false);
        $images->findPhoto(); // нахождение всех изображений исходного товара

        // Получаем все оригинальные фото конечного (текущего) товара
        $images = new ImagesModel($this);
        $images->useCache(false);
        $images->findPhoto();

        foreach(ImagesModel::$images['GoodsModel'][$source_id] as $num => $image_path ) {
            $next_num = $images->nextEmptyNumber();
            $path_arr = explode('.', $image_path);
            $ext = '.' . strtolower( $path_arr[count($path_arr)-1] );
            // новое имя файла
            $dest_path = $this->photo_path . '/' . $this->id . $ext;
            if ( $next_num > 0 ) {
                $dest_path = $this->photo_path . '/' . $this->id . '_' . $next_num . $ext;
            }
            ImagesModel::simpleCopy($image_path, $dest_path);
        }
    }

    /*
     * Расчет стоимости доставки одного товара
     *
     * @param boolean $update Обновить информацию о доставке товара в бд
     *
     * return integer
     */
    public function calcDeliveryPrice($update = true) {
        $deliveryPrice          = 0;
        $deliveryPrice_byMass   = 0;
        $deliveryPrice_byGroup  = 0;
        $deliveryPrice_case     = null;
        $characteristics = [];

        // Информация о доставке из группы товара
        $delivery_info_group = GroupsModel::findOrFail($this->grpid)->deliveryInfo();

        if ( $delivery_info_group['deliveryPrice'] <= 0 ) {
            $deliveryPrice_byGroup = SettingsModel::deliveryPrice_base();
        }
        else {
            $deliveryPrice_byGroup = $delivery_info_group['deliveryPrice'];
        }

        // Вернуться сюда и избавиться от захардкоденых цифр! например: OffersModel::deliveryPrice($mass);
        $mass = self::getMass($this->id);
        if ($mass > 0) {
            if ($mass >= 4 ) {
                $deliveryPrice_byMass = 450;
            }
            if ($mass > 25 ) {
                $deliveryPrice_byMass = 700;
            }
        }

        $deliveryPrice_byGroup  = ($deliveryPrice_byMass > 0 ? $deliveryPrice_byMass : $deliveryPrice_byGroup);

        if ( $delivery_info_group['deliveryParam'] > 0 ) {
            $characteristics = CharacteristicsModel::findOrFail($delivery_info_group['deliveryParam']);
            if ($characteristics->matchExpression($this)) {
                $deliveryPrice_byGroup = $delivery_info_group['deliveryPrice'];
            }
        }

        $deliveryPrice = max($deliveryPrice_byGroup, $deliveryPrice_byMass);
        if ($deliveryPrice_byGroup > $deliveryPrice_byMass) {
            $deliveryPrice = $deliveryPrice_byGroup;
            $deliveryPrice_case = 'hara';
        }
        else {
            $deliveryPrice = $deliveryPrice_byMass;
            $deliveryPrice_case = 'mass';
        }

        if ( $update ) {
            $this->update([
                "deliveryPrice" => $deliveryPrice,
                "deliveryPrice_case" => $deliveryPrice_case
            ]);
        }

        return $deliveryPrice;
    }


    /*
     * Возвращает значение характеристики, влиящей на стоимость доставки
     *
     * @return integer|boolean
     */
    public function deliveryPrice_value() {

        // У товара нет расчитанной стоимости доставки
        if ( is_null($this->deliveryPrice) ) {
            $this->deliveryPrice = $this->calcDeliveryPrice();
        }

        $tree = GroupsModel::parentsGroups($this->grpid);
        if ( is_null($tree) ) {
            return null;
        }

        $propy_id = null;
        foreach($tree AS $data) {
            if ( ! is_null($data->deliveryPrice_hara) ) {
                $propy_id = $data->deliveryPrice_hara;
                break;
            }
        }
        if ( is_null($propy_id) ) {
            return null;
        }

        $ch = CharacteristicsValuesModel::where('good_id', $this->id)->where('propy_id', $propy_id)->first();
        return $ch->value ?? null;
    }

    public function isDeliveryFree() {
        $deliveryFree_groups = SettingsModel::proc_delivery_g();
        $deliveryFree_groups = explode(",", $deliveryFree_groups);
        $deliveryFree_price  = SettingsModel::proc_delivery_bound();

        if ($this->r_flag==1 && !in_array($this->grpid, $deliveryFree_groups) && $this->price>=$deliveryFree_price ) {
            return true;
        }

        return false;
    }
}
