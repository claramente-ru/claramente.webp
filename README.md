[![Claramente](https://claramente.ru/upload/claramente/a2c/ho3rj4p3j2t7scsartohgjajkb1xkyh0/logo.svg)](https://claramente.ru)

# Claramente.webp
Модуль сжатия изображений в формат webp для Bitrix.

## Назначение
Модуль содержит расширенный класс `CFileExt` с дополнительным методом статическим методом `ResizeToWebpImageGet`.

Метод уменьшает картинку и размещает уменьшенную копию в папку /upload/resize_cache и при этом конвертирует изображение в формат `webp` вне зависимости от формата исходного изображения.

Опционально метод позволяет закрасить прозрачные участки изображения в указанный цвет.

## Установка
Модуль можно установить через composer:
```bash
composer require claramente/claramente.webp 
```
Установка модулей Битрикс по умолчанию производится в папку `bitrix/modules` (автоматически через plugin `composer/installers`).
Если необходима установка модуля по пути `local/modules`, то необходимо внести изменения в раздел `extra` в вашем `composer.json`, указав путь для установки Битрикс модулей:
```
  "extra": {
    "installer-paths": {
      "local/modules/{$name}/": ["type:bitrix-module"]
    }
  },
```
И выполнив переустановку модуля командой `composer require claramente/claramente.webp`.

## Подключение
После установки модуль необходимо активировать через административный раздел Битрикс.

Далее нужно добавить подключение модуля при помощи команды:
```php
Bitrix\Main\Loader::includeModule('claramente.webp');
```
например в файле `local/php_interface/init.php`.

## Использование
### Метод сжатия
Метод `ResizeToWebpImageGet` схож со стандартным методом Битрикс `\CFile::ResizeImageGet` и используется аналогично.

```php
$resizeResult = \Claramente\CFileExt::ResizeToWebpImageGet(
    file: $file,                          // Файл
    arSize: $arSize,                      // Размеры
    resizeType: $resizeType,              // Тип масштабирования (опционально)
    bInitSizes: $bInitSizes,              // Флаг возвращения размеров (опционально)
    arFilters: $arFilters,                // Фильтры (опционально)
    bImmediate:  $bImmediate,             // Флаг для обработчика события OnBeforeResizeImage (опционально)
    jpgQuality: $jpgQuality,              // Качество JPG при масштабировании (опционально)
    backgroundColor: $backgroundColor);   // Фоновый цвет \Bitrix\Main\File\Image\Color (опционально) 
```
Подробнее о передаваемых параметрах можно прочитать [здесь](https://dev.1c-bitrix.ru/api_help/main/reference/cfile/resizeimageget.php).

### Закраска фона
В качестве параметра backgroundColor для закраски прозрачных участков изображения передается \Bitrix\Main\File\Image\Color. Его можно создать через статический метод `createFromHex`:
```php
$backgroundColor = \Bitrix\Main\File\Image\Color::createFromHex('f3f3f3');
```


### Автоматизация
Удобным решением будет создание и использование статического метода, который в зависимости от активации и деактивации данного модуля будем использовать стандартный метод `\CFile::ResizeImageGet` или расширенный метод `\Claramente\CFileExt::ResizeToWebpImageGet`:   
```php
public static function resizeImageGetExt(
        $file,
        $arSize,
        $resizeType = BX_RESIZE_IMAGE_PROPORTIONAL,
        $bInitSizes = false,
        $arFilters = false,
        $bImmediate = false,
        $jpgQuality = false,
        $backgroundColor = null
    ): mixed
    {
        // Пережатие изображения
        if (class_exists('\Claramente\CFileExt')) {
            $resizeResult = \Claramente\CFileExt::ResizeToWebpImageGet(
                file: $file,
                arSize: $arSize,
                resizeType: $resizeType,
                bInitSizes: $bInitSizes,
                arFilters: $arFilters,
                bImmediate:  $bImmediate,
                jpgQuality: $jpgQuality,
                backgroundColor: $backgroundColor);
        } else {
            $resizeResult = \CFile::ResizeImageGet(
                file: $file,
                arSize: $arSize,
                resizeType: $resizeType,
                bInitSizes: $bInitSizes,
                arFilters: $arFilters,
                bImmediate: $bImmediate,
                jpgQuality: $jpgQuality);
        }

        // Возвращаем результат
        return $resizeResult;
    }
```
## Лицензия
MIT. Вы можете посмотреть [текст лицензии](LICENSE) для подробной информации.