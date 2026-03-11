<?php

declare(strict_types=1);

namespace Magput\Debug\helpers;

use Exception;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Yii;

class JsonHelper
{
    private static Serializer $serializer;

    private static function initializeSerializer(): void
    {
        if (!isset(self::$serializer)) {
            self::$serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        }
    }
    /**
     * Кодирует данные в строку JSON.
     * Если кодирование не удалось, возвращает значение по умолчанию.
     *
     * @param mixed $data Данные для кодирования.
     * @param int $options Дополнительные опции для json_encode.
     * @param mixed|null $default Значение по умолчанию, если кодирование не удалось.
     * @return string|string[]|null Декодированный JSON или значение по умолчанию.
     */
    public static function encode(mixed $data, int $options = 0, mixed $default = null): array|string|null
    {
        self::initializeSerializer();

        try {
            return self::$serializer->encode($data, 'json', [
                'json_encode_options' => $options,
            ]);
        } catch (Exception $e) {
            Yii::info($e->getMessage());
            return $default;
        }
    }

    /**
     * Декодирует строку JSON в массив или объект.
     * Если строка не является корректным JSON, возвращает значение по умолчанию.
     *
     * @param string|null $json JSON строка для декодирования.
     * @param bool $asArray Если true, декодирует в ассоциативный массив. Иначе возвращает объект.
     * @param mixed|null $default Значение по умолчанию, если декодирование не удалось.
     * @return mixed Декодированный JSON или значение по умолчанию.
     */
    public static function decode(?string $json, bool $asArray = true, mixed $default = null): mixed
    {
        self::initializeSerializer();

        if ($json === null || $json === '') {
            return $default;
        }

        try {
            return self::$serializer->decode($json, 'json');
        } catch (Exception $e) {
            Yii::info($e->getMessage());
            return $default;
        }
    }
}
