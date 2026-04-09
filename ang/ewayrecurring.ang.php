<?php

// Angular module ewayrecurring.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
return [
  'js' => [
    'ang/ewayrecurring.module.js',
    'ang/ewayrecurring/*.js',
    'ang/ewayrecurring/*/*.js',
  ],
  'partials' => [
    'ang/ewayrecurring',
  ],
  'css' => [
    'ang/ewayrecurring.css',
  ],
  'basePages' => [],
  'requires' => ['crmUi', 'crmUtil', 'dialogService', 'api4', 'checklist-model', 'crmDialog'],
  'settingsFactory' => [\Civi\Search\Actions::class, 'getActionSettings'],
];
