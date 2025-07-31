<?php
/*
 * Этот класс расширяет CFile, добавляя возможность изменения формата изображения
 * одновременно с изменением его формата на webp.
 */

namespace Claramente;

use Bitrix\Main\File;
use Bitrix\Main\File\Image;
use Bitrix\Main\File\Image\Rectangle;
use CFile;
use COption;
use CBXVirtualIo;
use CDiskQuota;

class CFileExt extends CFile
{
    public static function ResizeToWebpImageGet(
        $file,
        $arSize,
        $resizeType = BX_RESIZE_IMAGE_PROPORTIONAL,
        $bInitSizes = false,
        $arFilters = false,
        $bImmediate = false,
        $jpgQuality = false,
        $backgroundColor = null
    )
    {
        // Чтение данных файла, если передан только его идентификатор
        if (!is_array($file) && intval($file) > 0)
        {
            $file = static::GetFileArray($file);
        }

        // Отмена преобразования отсутствуют данные файла
        if (!is_array($file) || !array_key_exists('FILE_NAME', $file) || $file['FILE_NAME'] == '')
            return false;

        // Использовать пропорциональное сжатие, если передан его невалидный тип
        if ($resizeType !== BX_RESIZE_IMAGE_EXACT && $resizeType !== BX_RESIZE_IMAGE_PROPORTIONAL_ALT)
            $resizeType = BX_RESIZE_IMAGE_PROPORTIONAL;

        // Приведение типов для значений ширины и высоты изображения, задание дефолтных значений
        if (!is_array($arSize))
            $arSize = [];
        if (!array_key_exists('width', $arSize) || intval($arSize['width']) <= 0)
            $arSize['width'] = 0;
        if (!array_key_exists('height', $arSize) || intval($arSize['height']) <= 0)
            $arSize['height'] = 0;
        $arSize['width'] = intval($arSize['width']);
        $arSize['height'] = intval($arSize['height']);

        // Получение директории загрузки
        $uploadDirName = COption::GetOptionString('main', 'upload_dir', 'upload');

        $arImageSize = false;

        // Имя файла
        $imageFile = '/'.$uploadDirName.'/'.$file['SUBDIR'].'/'.$file['FILE_NAME'];

        // Фильтры
        $bFilters = is_array($arFilters) && !empty($arFilters);

        // Если желаемые ширина и высота изображения отрицательные или выше текущих (upscale)
        if (
            ($arSize['width'] <= 0 || $arSize['width'] >= $file['WIDTH'])
            && ($arSize['height'] <= 0 || $arSize['height'] >= $file['HEIGHT'])
        )
        {
            // И если необходимо применение фильтров, то продолжим без изменения размеров
            if ($bFilters || $file['CONTENT_TYPE'] !== 'image/webp' || isset($backgroundColor)) {
                $arSize['width'] = $file['WIDTH'];
                $arSize['height'] = $file['HEIGHT'];
                $resizeType = BX_RESIZE_IMAGE_PROPORTIONAL;
            } else {
                // Иначе исправляем настройки ширины и высоты изображения для запроса с облачного хранилища
                if (isset($file['SRC'])) {
                    global $arCloudImageSizeCache;
                    $arCloudImageSizeCache[$file['SRC']] = [$file['WIDTH'], $file['HEIGHT']];
                } else {
                    trigger_error(
                        "Parameter \$file for CFile::ResizeToWebpImageGet does not have SRC element. You'd better pass an b_file.ID as a value for the \$file parameter.",
                        E_USER_WARNING
                    );
                }
                // Возвращаем изображение без изменений
                return [
                    'src' => $file['SRC'],
                    'width' => intval($file['WIDTH']),
                    'height' => intval($file['HEIGHT']),
                    'size' => $file['FILE_SIZE'],
                ];
            }
        }

        $io = CBXVirtualIo::GetInstance();
        $cacheImageFile = '/' . $uploadDirName . '/resize_cache/' . $file['SUBDIR'] . '/'
            . $arSize['width'] . '_' . $arSize['height'] . '_' . $resizeType
            . (is_array($arFilters) ? md5(serialize($arFilters)) : '')
            . '/' . $file['FILE_NAME'];

        // Указываем новое расширение для типа файла изображения после преобразования
        if ($file['CONTENT_TYPE'] !== 'image/webp') {
            $cacheImageFile .= '.webp';
        }

        // Проверяем наличие уже преобразованного файла в кэше
        $cacheImageFileCheck = $cacheImageFile;
        static $cache = [];
        $cache_id = $cacheImageFileCheck;
        // Если изображение в кэше, то вернем его
        if (isset($cache[$cache_id])) {
            return $cache[$cache_id];
        }
        // А если его нет ни в кэше, ни на диске
        elseif (!file_exists($io->GetPhysicalName($_SERVER['DOCUMENT_ROOT'].$cacheImageFileCheck)))
        {
            // Если фильтры не заданы, то используем для них дефолтные значения
            if (!is_array($arFilters)) {
                $arFilters = [
                    ['name' => 'sharpen', 'precision' => 15]
                ];
            }

            $sourceImageFile = $_SERVER['DOCUMENT_ROOT'].$imageFile;
            $cacheImageFileTmp = $_SERVER['DOCUMENT_ROOT'].$cacheImageFile;
            $bNeedResize = true;
            $callbackData = null;

            // Вызываем события OnBeforeResizeImage перед изменением размера изображения
            foreach (GetModuleEvents('main', 'OnBeforeResizeImage', true) as $arEvent)
            {
                if (ExecuteModuleEventEx($arEvent, [
                    $file,
                    [$arSize, $resizeType, [], false, $arFilters, $bImmediate],
                    &$callbackData,
                    &$bNeedResize,
                    &$sourceImageFile,
                    &$cacheImageFileTmp,
                ])) {
                    break;
                }
            }

            // Если все еще необходимо преобразование изображения, то преобразовываем его
            if ($bNeedResize && static::ResizeToWebpImageFile(
                    $sourceImageFile,
                    $cacheImageFileTmp,
                    $arSize,
                    $resizeType,
                    [],
                    $jpgQuality,
                    $arFilters,
                    $backgroundColor
                )) {
                $cacheImageFile = mb_substr($cacheImageFileTmp, mb_strlen($_SERVER['DOCUMENT_ROOT']));

                // Обновив данные квотирования дискового пространства на размер полученного файла
                /****************************** QUOTA ******************************/
                if (COption::GetOptionInt('main', 'disk_space') > 0)
                    CDiskQuota::updateDiskQuota('file', filesize($io->GetPhysicalName($cacheImageFileTmp)), 'insert');
                /****************************** QUOTA ******************************/
            } else {
                // Иначе вернем исходный файл
                $cacheImageFile = $imageFile;
            }

            // Вызываем события OnAfterResizeImage после изменением размера изображения
            foreach (GetModuleEvents('main', 'OnAfterResizeImage', true) as $arEvent)
            {
                if (ExecuteModuleEventEx($arEvent, [
                    $file,
                    [$arSize, $resizeType, [], false, $arFilters],
                    &$callbackData,
                    &$cacheImageFile,
                    &$cacheImageFileTmp,
                    &$arImageSize,
                ]))
                    break;
            }
            $cacheImageFileCheck = $cacheImageFile;
        } elseif (defined('BX_FILE_USE_FLOCK')) {
            // Снимем блокировку с исходного файла, если Битрикс использует ее
            $hLock = $io->OpenFile($_SERVER['DOCUMENT_ROOT'].$imageFile, 'r+');
            if ($hLock) {
                flock($hLock, LOCK_EX);
                flock($hLock, LOCK_UN);
                fclose($hLock);
            }
        }

        // Если требуется вернуть размеры изображения, данных о которых нет,
        if ($bInitSizes && !is_array($arImageSize)) {
            $imageInfo = (new File\Image($_SERVER['DOCUMENT_ROOT'].$cacheImageFileCheck))->getInfo();
            // То получаем информацию о файле и возвращаем размеры
            if ($imageInfo) {
                $arImageSize[0] = $imageInfo->getWidth();
                $arImageSize[1] = $imageInfo->getHeight();
            } else {
                // А если ее нет, устанавливаем значения в 0
                $arImageSize = [0, 0];
            }
            // Также получим размер файла
            $f = $io->GetFile($_SERVER['DOCUMENT_ROOT'].$cacheImageFileCheck);
            $arImageSize[2] = $f->GetFileSize();
        }

        // Если нет информации о размерах изображения, то устанавливаем все значения в 0
        if (!is_array($arImageSize))
        {
            $arImageSize = [0, 0, 0];
        }

        // Добавляем файл в кэш и возвращаем информацию по файлу изображения
        $cache[$cache_id] = [
            'src' => $cacheImageFileCheck,
            'width' => intval($arImageSize[0]),
            'height' => intval($arImageSize[1]),
            'size' => $arImageSize[2]
        ];
        return $cache[$cache_id];
    }

    public static function ResizeToWebpImageFile(
        $sourceFile,
        &$destinationFile,
        $arSize,
        $resizeType = BX_RESIZE_IMAGE_PROPORTIONAL,
        $arWaterMark = [],
        $quality = false,
        $arFilters = false,
        $backgroundColor = null
    )
    {
        // Проверяем существует ли исходный файл изображения
        $io = CBXVirtualIo::GetInstance();
        if (!$io->FileExists($sourceFile)) {
            return false;
        }

        // Использовать пропорциональное сжатие, если передан его невалидный тип
        if ($resizeType !== BX_RESIZE_IMAGE_EXACT && $resizeType !== BX_RESIZE_IMAGE_PROPORTIONAL_ALT) {
            $resizeType = BX_RESIZE_IMAGE_PROPORTIONAL;
        }

        // Приведение типов для значений ширины и высоты изображения, задание дефолтных значений
        if (!is_array($arSize)) {
            $arSize = [];
        }
        if (!array_key_exists('width', $arSize) || intval($arSize['width']) <= 0) {
            $arSize['width'] = 0;
        }
        if (!array_key_exists('height', $arSize) || intval($arSize['height']) <= 0) {
            $arSize['height'] = 0;
        }

        $arSize['width'] = intval($arSize['width']);
        $arSize['height'] = intval($arSize['height']);

        // Получение информации о файле источнике и формате изображения
        $sourceImage = new File\Image($io->GetPhysicalName($sourceFile));
        $sourceInfo = $sourceImage->getInfo();

        if ($sourceInfo === null || !$sourceInfo->isSupported()) {

            return false;
        }

        $fileType = $sourceInfo->getFormat();

        // Используем корректную ориентацию изображения из EXIF для JPEG-формата
        $orientation = 0;
        if ($fileType == File\Image::FORMAT_JPEG) {
            $exifData = $sourceImage->getExifData();
            if (isset($exifData['Orientation'])) {
                $orientation = $exifData['Orientation'];
                //swap width and height
                if ($orientation >= 5 && $orientation <= 8) {
                    $sourceInfo->swapSides();
                }
            }
        }

        // Определение необходимости изменять размер изображения
        $result = false;

        $sourceRectangle = $sourceInfo->toRectangle();
        $destinationRectangle = new Rectangle($arSize['width'], $arSize['height']);

        $needResize = $sourceRectangle->resize($destinationRectangle, $resizeType);

        $hLock = $io->OpenFile($sourceFile, 'r+');
        $useLock = defined('BX_FILE_USE_FLOCK');

        // Открыть исходный файл и заблокировать (опционально)
        if ($hLock) {
            if ($useLock) {
                flock($hLock, LOCK_EX);
            }
            // Если файл, где будет сохранено преобразованное изображение существует, то проверим его размеры.
            // Возможно преобразование уже выполнено и делать его повторно не требуется.
            if ($io->FileExists($destinationFile)) {
                $destinationInfo = (new File\Image($io->GetPhysicalName($destinationFile)))->getInfo();
                if ($destinationInfo) {
                    if($destinationInfo->getWidth() == $destinationRectangle->getWidth() && $destinationInfo->getHeight() == $destinationRectangle->getHeight()) {
                        //nothing to do
                        $result = true;
                    }
                }
            }
        }
        // Преобразование изображения с изменением размера, нанесением водяных знаков,
        // использованием фильтров и сохранением в формат webp
        if($result === false) {

            if ($io->Copy($sourceFile, $destinationFile)) {

                // Если передан цвет фона,
                if (isset($backgroundColor)) {
                    // То загрузим изображение в нужном формате: png или webp
                    if ($fileType === File\Image::FORMAT_PNG) {
                        $imageResource = imagecreatefrompng($destinationFile);
                    } elseif ($fileType === File\Image::FORMAT_WEBP) {
                        $imageResource = imagecreatefromwebp($destinationFile);
                    }

                    // Если изображение успешно загружено,
                    if ($imageResource) {
                        // Определяем его размеры
                        $width = imagesx($imageResource);
                        $height = imagesy($imageResource);

                        // Создаем пустое изображение с такими же размерами
                        $gdImage = imagecreatetruecolor($width, $height);

                        // Создаем цвет для использования в изображении
                        $backColor = imagecolorallocate(
                            $gdImage,
                            $backgroundColor->getRed(),
                            $backgroundColor->getGreen(),
                            $backgroundColor->getBlue()
                        );

                        // Заполняем пустое изображение нужным цветом
                        imagefill($gdImage, 0, 0, $backColor);
                        // И переносим на него изображение с альфа-каналом
                        imagecopy($gdImage, $imageResource, 0, 0, 0, 0, $width, $height);
                    }

                    // Сохраняем полученное изображение в нужном формате: png или webp
                    if ($fileType === File\Image::FORMAT_PNG) {
                        imagepng($gdImage, $destinationFile);
                    } elseif ($fileType === File\Image::FORMAT_WEBP) {
                        imagewebp($gdImage, $destinationFile);
                    }
                }

                $destinationImage = new File\Image($io->GetPhysicalName($destinationFile));

                if ($destinationImage->load()) {
                    // Коррекция ориентации
                    if ($orientation > 1) {
                        $destinationImage->autoRotate($orientation);
                    }


                    $modified = false;
                    if ($needResize) {
                        // Изменение размера изображения
                        $sourceRectangle = $destinationImage->getDimensions();
                        $destinationRectangle = new Rectangle($arSize['width'], $arSize['height']);

                        $sourceRectangle->resize($destinationRectangle, $resizeType);

                        $modified = $destinationImage->resize($sourceRectangle, $destinationRectangle);
                    }

                    // Устанавливаем флаг модификации для случаев, когда исходный файл был в формате webp
                    if ($fileType === File\Image::FORMAT_WEBP) {
                        $modified = true;
                    }

                    // Объединяем фильтры и водяные знаки
                    if (!is_array($arFilters)) {
                        $arFilters = [];
                    }

                    if (is_array($arWaterMark)) {
                        $arWaterMark['name'] = 'watermark';
                        $arFilters[] = $arWaterMark;
                    }

                    // Применение фильтров и нанесение водяных знаков
                    foreach ($arFilters as $arFilter) {
                        if($arFilter['name'] == 'sharpen' && $arFilter['precision'] > 0) {
                            $modified |= $destinationImage->filter(File\Image\Mask::createSharpen($arFilter['precision']));
                        } elseif ($arFilter['name'] == 'watermark') {
                            $watermark = Image\Watermark::createFromArray($arFilter);
                            $modified |= $destinationImage->drawWatermark($watermark);
                        }
                    }

                    // Если не задано качество сжатия, то используется значение по умолчанию
                    if ($modified) {
                        if($quality === false) {
                            $quality = COption::GetOptionString('main', 'image_resize_quality');
                        }



                        // Удаление временного файла
                        $io->Delete($destinationFile);

                        // Сохраняем полученное изображение в фромате webp
                        if($fileType !== File\Image::FORMAT_WEBP) {
                            $destinationImage->saveAs($io->GetPhysicalName($destinationFile), $quality, File\Image::FORMAT_WEBP);
                        } else {
                            $destinationImage->save($quality);
                        }

                        $destinationImage->clear();
                    }
                }

                $result = true;
            }
        }

        // Закрыть исходный файл и снять блокировку (опционально)
        if ($hLock) {
            if ($useLock) {
                flock($hLock, LOCK_UN);
            }
            fclose($hLock);
        }

        return $result;
    }
}