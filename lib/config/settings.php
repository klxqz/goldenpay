<?php
return array(

    'userName'    => array(
        'value'        => '',
        'title'        => 'Логин магазина',
        'description'  => 'Логин магазина, полученный при подключении',
        'control_type' => waHtmlControl::INPUT,
    ),
    'password'    => array(
        'value'        => '',
        'title'        => 'Пароль магазина',
        'description'  => 'Пароль магазина, полученный при подключении',
        'control_type' => waHtmlControl::INPUT,
    ),
    'lang'    => array(
        'value'        => 'lv',
        'title'        => 'Язык',
        'description'  => '',
        'control_type' => waHtmlControl::SELECT,
        'options' => array(
            'lv' => 'Азербайджанский',
            'en' => 'Английский',
            'ru' => 'Русский',
        )
    ),

);
