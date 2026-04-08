<?php

// Angular module ewayrecurring.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
return [
  'js' => [
    'ang/ewayrecurring.js',
    'ang/ewayrecurring/*.js',
    'ang/ewayrecurring/*/*.js',
  ],
  'css' => [
    'ang/ewayrecurring.css',
  ],
  'partials' => [
    'ang/ewayrecurring',
  ],
  'requires' => ['crmUi', 'crmUtil', 'ngRoute'],
  'settings' => [],
];
