<?php

require_once './vendor/autoload.php';

$loader = new Twig_Loader_Filesystem('./template');
$twig = new Twig_Environment($loader, []);

foreach (glob('./xml/*.xml') as $file) {
  $xml = simplexml_load_file($file);
  $out = str_replace('xml', 'html', $file);

  $abilities = abilify($xml);
  $skills = skillify($xml);
  $spells = spellify($xml);
  $actions = actions($xml);
  $saves = saves($xml);

  file_put_contents($out, $twig->render('character.twig', [
    'character' => $xml->character,
    'skills' => $skills,
    'abilities' => $abilities,
    'spells' => $spells,
    'actions' => $actions,
    'saves' => $saves,
  ]));
}

function bab($xml) {
  $bab = 0;
  foreach ($xml->character->class as $class) {
    $bab += (int) $class->baseAttack;
  }
  return $bab;
}

function saves($xml) {
  $able = abilify($xml);
  $saves = [
    'fortitude' => 0,
    'reflex' => 0,
    'will' => 0,
  ];
  foreach ($xml->character->class as $class) {
    foreach (array_keys($saves) as $save) {
      $saves[$save] += (int) $class->$save;
    }
  }
  $saves['fortitude'] += $able['CON']['bonus'];
  $saves['reflex'] += $able['DEX']['bonus'];
  $saves['will'] += $able['WIS']['bonus'];
  return $saves;
}

function actions($xml) {
  $able = abilify($xml);
  $bab = bab($xml);
  $map = [
    '%1' => $able['STR']['bonus'],
    '%2' => $able['DEX']['bonus'],
    '%3' => $able['CON']['bonus'],
    '%4' => $able['INT']['bonus'],
    '%5' => $able['WIS']['bonus'],
    '%6' => $able['CHA']['bonus'],
    '%8' => $bab,
    '%9' => $bab + $able['STR']['bonus'],
    '%10' => $bab + $able['DEX']['bonus'],
  ];

  $actions = [];
  foreach ($xml->character->action as $action) {
    $attacks = [];
    foreach ($action->attack as $attack) {
      foreach ($map as $key=>$val){
        $attack = str_replace($key, $val, $attack);
      }
      //print $attack;
      $attack = eval("return {$attack};");
      $attacks[] = "+" . $attack;
    }
    $name = (string)$action->name;
    $actions[$name] = [
      'attacks' => $attacks,
      'damage' => (string) $action->damage,
    ];
  }
  return $actions;
}

function spellify($xml) {
  $spells = [];
  foreach ($xml->character->class as $class) {
    if ($class->spell) {
      $class_name = (string) $class->name;
      $spells[$class_name] = [];
      foreach ($class->spell as $spell) {
        $level = $spell->level ? (string) $spell->level : '0';
        if (!isset($spells[$class_name][$level])) {
          $spells[$class_name][$level] = [];
        }
        $spells[$class_name][$level][(string) $spell->name] = $spell;
      }
    }
  }

  // Sort everything
  ksort($spells);
  foreach (array_keys($spells) as $class) {
    ksort($spells[$class]);
    foreach (array_keys($spells[$class]) as $level) {
      ksort($spells[$class][$level]);
    }
  }
  //print_r($spells);
  return $spells;
}

function abilify($xml) {
  $abilities = explode(',', $xml->character->abilities);
  //print_r($abilities);
  $vals = [];
  foreach (['STR', 'DEX', 'CON', 'INT', 'WIS', 'CHA'] as $ability) {
    $vals[$ability] = [
      'bonus' => bonusify($abilities[abilityMap($ability)]),
      'raw' => $abilities[abilityMap($ability)],
    ];
  }
  return $vals;
}

function abilityMap($in) {
  $map = [
    'STR' => 1,
    'DEX' => 2,
    'CON' => 3,
    'INT' => 4,
    'WIS' => 5,
    'CHA' => 6,
  ];
  return is_numeric($in) ? array_search($in, $map) : $map[$in];
}

function bonusify($raw) {
  return floor($raw / 2) - 5;
}



function skillify($xml) {
  $abilities = abilify($xml);
  $skills = [];
  foreach ($xml->character->skill as $skill) {
    $s = [
      'name' => (string) $skill->name,
      'rank' => (int) $skill->rank,
      'ability' => (int) $skill->ability,
      'armorPenalty' => - (int) $skill->armorPenalty,
      'modifier' => 0,
    ];
    $s['rank'] = $s['rank'] ? $s['rank'] : 0;
    $s['ability'] = $s['ability'] ? abilityMap($s['ability']) : 'INT';
    $s['abilityBonus'] = $abilities[$s['ability']]['bonus'];
    $s['total'] =  $s['rank'] + $s['abilityBonus'] + $s['armorPenalty'];
    $s['trained'] = trained($s['name']);
    $skills[] = $s;
  }

  for ($i=0; $i<count($skills); $i++) {
    $synergies = synergize($skills[$i]['name']);
    foreach ($synergies as $synergy) {
      $skills[$i]['modifier'] += rank($synergy, $skills) > 4 ? 2 : 0;
      $skills[$i]['total'] += rank($synergy, $skills) > 4 ? 2 : 0;
    }
  }
  return $skills;
}

function trained($skill) {
  $trained = [
    'Decipher Script',
    'Disable Device',
    'Handle Animal',
    'Open Lock',
    'Profession',
    'Sleight of Hand',
    'Speak Language',
    'Spellcraft',
    'Tumble',
    'Use Magic Device'
  ];
  return in_array($skill, $trained) || preg_match('/Knowledge/', $skill);
}

function rank($name, $skills) {
  foreach ($skills as $skill) {
    if ($skill['name'] == $name) {
      return $skill['rank'];
    }
  }
  return 0;
}

function synergize($skill) {
  $map = [
    'Diplomacy' => ['Bluff', 'Sense Motive', 'Knowledge (nobility and royalty)'],
    'Disguse' => ['Bluff'],
    'Intimidate' => ['Bluff'],
    'Sleight of Hand' => ['Bluff'],
    'Ride' => ['Handle Animal'],
    'Wild Empathy' => ['Handle Animal'],
    'Tumble' => ['Jump'],
    'Jump' => ['Tumble'],
    'Balance' => ['Tumble'],
    'Spellcraft' => ['Knowledge (arcana)'],
    'Bardic Knowledge' => ['Knowledge (history)'],
    'Gather Information' => ['Knowledge (local)'],
    'Turn Undead' => ['Knowledge (religion)'],
    'Knowledge (nature)' => ['Survival']
  ];
  return isset($map[$skill]) ? $map[$skill] : [];
}

function semiSynergize($skill) {
  $map = [
    'Appraise' => ['Decipher Script'],
    'Use Magic Device' => ['Decipher Script', 'Spellcraft'],
    'Use Rope' => ['Escape Artist'],
    'Intimidate' => ['Bluff'],
    'Sleight of Hand' => ['Bluff'],
    'Spellcraft' => ['Knowledge (architecture and engineering)', 'Use Magic Device'],
    'Survival' => ['Knowledge (dungeoneering)', 'Knowledge (geography)', 'Knowledge (nature)', 'Knowledge (the plains)', 'Search'],
    'Escape Artist' => ['Use Rope'],
    'Climb' => ['Use Rope'],
  ];
  return isset($map[$skill]) ? $map[$skill] : [];
}