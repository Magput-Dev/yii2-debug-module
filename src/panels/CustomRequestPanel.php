<?php

declare(strict_types=1);

namespace Magput\Debug\panels;

use yii\debug\panels\RequestPanel;

class CustomRequestPanel extends RequestPanel
{
    public function save()
    {
        $data = parent::save();

        unset($data['ENV']);
        unset($data['SERVER']);
        unset($data['SESSION']);

        return $data;
    }
}
