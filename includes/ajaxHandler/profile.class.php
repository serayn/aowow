<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');

class AjaxProfile extends AjaxHandler
{
    protected $validParams = ['link', 'unlink', 'pin', 'unpin', 'public', 'private', 'avatar', 'resync', 'status', 'delete', 'purge', 'summary', 'load'];
    protected $_get        = array(
        'id'     => [FILTER_CALLBACK,            ['options' => 'AjaxProfile::checkId']],
        // 'items'  => [FILTER_CALLBACK,            ['options' => 'AjaxProfile::checkItems']],
        'size'   => [FILTER_SANITIZE_STRING, 0xC], // FILTER_FLAG_STRIP_LOW | *_HIGH
    );

    public function __construct(array $params)
    {
        parent::__construct($params);

        if (!$this->params)
            return;

        switch ($this->params[0])
        {
            case 'link':
            case 'unlink':
                $this->handler = 'handleLink';              // always returns null
                break;
            case 'pin':
            case 'unpin':
                $this->handler = 'handlePin';               // always returns null
                break;
            case 'public':
            case 'private':
                $this->handler = 'handlePrivacy';           // always returns null
                break;
            case 'avatar':
                $this->handler = 'handleAvatar';            // sets an image header
                break;                                      // so it has to die here or another header will be set
            case 'resync':
            case 'status':
                $this->handler = 'handleResync';
                break;
            case 'save':
                $this->handler = 'handleSave';
                break;
            case 'delete':
                $this->handler = 'handleDelete';
                break;
            case 'purge':
                $this->handler = 'handlePurge';
                break;
            case 'summary':                                 // page is generated by jScript
                die();                                      // just be empty
            case 'load':
                $this->handler = 'handleLoad';
                break;
        }
    }

    protected function handleLink($id, $mode)               // links char with account
    {
        /*  params
                id: <prId1,prId2,..,prIdN>
                user: <string> [optional]
            return: null
        */
    }

    protected function handlePin($id, $mode)                // (un)favorite
    {
        /*  params
                id: <prId1,prId2,..,prIdN>
                user: <string> [optional]
            return: null
        */
    }

    protected function handlePrivacy($id, $mode)            // public visibility
    {
        /*  params
                id: <prId1,prId2,..,prIdN>
                user: <string> [optional]
            return: null
        */
    }

    protected function handleAvatar()                       // image
    {
        // something happened in the last years: those textures do not include tiny icons
        $sizes = [/* 'tiny' => 15, */'small' => 18, 'medium' => 36, 'large' => 56];
        $aPath = 'uploads/avatars/%d.jpg';
        $s     = $this->_get['size'] ?: 'medium';

        if (!$this->_get['id'] || !preg_match('/^([0-9]+)\.(jpg|gif)$/', $this->_get['id'][0], $matches) || !in_array($s, array_keys($sizes)))
            return;

        $this->contentType = 'image/'.$matches[2];

        $id   = $matches[1];
        $dest = imageCreateTruecolor($sizes[$s], $sizes[$s]);

        if (file_exists(sprintf($aPath, $id)))
        {
            $offsetX = $offsetY = 0;

            switch ($s)
            {
                case 'tiny':
                    $offsetX += $sizes['small'];
                case 'small':
                    $offsetY += $sizes['medium'];
                case 'medium':
                    $offsetX += $sizes['large'];
            }

            $src = imageCreateFromJpeg(printf($aPath, $id));
            imagecopymerge($dest, $src, 0, 0, $offsetX, $offsetY, $sizes[$s], $sizes[$s], 100);
        }

        if ($matches[2] == 'gif')
            imageGif($dest);
        else
            imageJpeg($dest);

        return;
    }

    protected function handleResync()                       // resync init and status requests
    {
        /*  params
                id: <prId1,prId2,..,prIdN>
                user: <string> [optional]
            return
                null            [onOK]
                int or str      [onError]
        */

        if ($this->params[0] == 'resync')
        {
            if ($chars = DB::Aowow()->select('SELECT realm, realmGUID FROM ?_profiler_profiles WHERE id IN (?a)', $this->_get['id']))
                foreach ($chars as $c)
                    Util::scheduleResync(TYPE_PROFILE, $c['realm'], $c['realmGUID']);

            return '1';
        }
        else // $this->params[0] == 'status'
        {
            /*
                not all fields are required, if zero they are omitted
                statusCode:
                    0: end the request
                    1: waiting
                    2: working...
                    3: ready; click to view
                    4: error / retry
                errorCode:
                    0: unk error
                    1: char does not exist
                    2: armory gone

                [
                    nQueueProcesses,
                    [statusCode, timeToRefresh, curQueuePos, errorCode, nResyncTries],
                    [<anotherStatus>]...
                ]
            */
            $response = [(int)!!CFG_PROFILER_QUEUE];        // in theory you could have multiple queues but lets be frank .. you will NEVER be under THAT kind of load for it to be relevant
            if (!$this->_get['id'])
                $response[] = [PR_QUEUE_STATUS_ENDED, 0, 0, PR_QUEUE_ERROR_CHAR];
            else
            {
                $charGUIDs  = $this->_get['id'];
                $charStatus = DB::Aowow()->select('SELECT typeId AS ARRAY_KEY, status, realm FROM ?_profiler_sync WHERE `type` = ?d AND typeId IN (?a)', TYPE_PROFILE, $charGUIDs);
                $queue      = DB::Aowow()->selectCol('SELECT typeId FROM ?_profiler_sync WHERE `type` = ?d AND status = ?d AND requestTime < UNIX_TIMESTAMP() ORDER BY requestTime ASC', TYPE_PROFILE, PR_QUEUE_STATUS_WAITING);
                foreach ($charGUIDs as $guid)
                {
                    if (empty($charStatus[$guid]))         // whelp, thats some error..
                        $response[] = [PR_QUEUE_STATUS_ERROR, 0, 0, PR_QUEUE_ERROR_UNK];
                    else if ($charStatus[$guid]['status'] == PR_QUEUE_STATUS_ERROR)
                        $response[] = [PR_QUEUE_STATUS_ERROR, 0, 0, $charStatus[$guid]['errCode']];
                    else
                        $response[] = [$charStatus[$guid]['status'], CFG_PROFILER_RESYNC_PING, array_search($guid, $queue) + 1, 0, 1];
                }
            }
            return Util::toJSON($response);        }
    }

    protected function handleSave()                         // unKill a profile
    {
        /*  params GET
                id: <prId1,prId2,..,prIdN>
            params POST
                name, level, class, race, gender, nomodel, talenttree1, talenttree2, talenttree3, activespec, talentbuild1, glyphs1, talentbuild2, glyphs2, gearscore, icon, public     [always]
                description, source, copy, inv { inventory: array containing itemLinks }                                                                                                [optional]
                }
            return
                int > 0     [profileId, if we came from an armoryProfile create a new one]
                int < 0     [onError]
                str         [onError]
        */

        return 'NYI';
    }

    protected function handleDelete()                       // kill a profile
    {
        /*  params
                id: <prId1,prId2,..,prIdN>
            return
                null
        */

        return 'NYI';
    }

    protected function handlePurge()                        // removes certain saved information but not the entire character
    {
        /*  params
                id: <prId1,prId2,..,prIdN>
                data: <mode>                [string, tabName?]
            return
                null
        */

        return 'NYI';
    }

    protected function handleLoad()
    {
        /*  params
                id: profileId
                items: string       [itemIds.join(':')]
                unnamed: unixtime   [only to force the browser to reload instead of cache]
            return
                lots...
        */

        // titles, achievements, characterData, talents (, pets)
        // and some onLoad-hook to .. load it registerProfile($data)
        // everything else goes through data.php .. strangely enough

        if (!$this->_get['id'])
            return;

        $pBase = DB::Aowow()->selectRow('SELECT *, UNIX_TIMESTAMP(lastupdated) AS lastupdated FROM ?_profiler_profiles WHERE id = ?d', $this->_get['id'][0]);
        if (!$pBase)
            return 'alert("whoops!");';

        $rData = [];
        foreach (Util::getRealms() as $rId => $rData)
            if ($rId == $pBase['realm'])
                break;

        $spec1 = explode(' ', $pBase['spec1']);
        $spec2 = explode(' ', $pBase['spec2']);

        $profile = array(
            'source'            => $pBase['id'],            // source: used if you create a profile from a genuine character. It inherites region, realm and bGroup
            'id'                => $pBase['id'],
            'name'              => $pBase['name'],
            'region'            => [$rData['region'], Lang::profiler('regions', $rData['region'])],
            'battlegroup'       => [Util::urlize(CFG_BATTLEGROUP), CFG_BATTLEGROUP],
            'realm'             => [Util::urlize($rData['name']), $rData['name']],
            'level'             => $pBase['level'],
            'classs'            => $pBase['class'],
            'race'              => $pBase['race'],
            'faction'           => Game::sideByRaceMask(1 << ($pBase['race'] - 1), true),
            'gender'            => $pBase['gender'],
            'skincolor'         => $pBase['skincolor'],
            'hairstyle'         => $pBase['hairstyle'],
            'haircolor'         => $pBase['haircolor'],
            'facetype'          => $pBase['facetype'],
            'features'          => $pBase['features'],
            'published'         => !!($pBase['cuFlags'] & PROFILE_CU_PUBLISHED),
            'pinned'            => !!($pBase['cuFlags'] & PROFILE_CU_PINNED),
            'inventory'         => [],
            'nomodel'           => $pBase['nomodelMask'],
            'title'             => $pBase['title'],
            'playedtime'        => $pBase['playedtime'],
            'lastupdated'       => $pBase['lastupdated'] * 1000,
            'talents'           => array(
                'builds' => array(
                    ['talents' => array_shift($spec1) . array_shift($spec1) . array_shift($spec1), 'glyphs' => implode(':', $spec1)],
                    ['talents' => array_shift($spec2) . array_shift($spec2) . array_shift($spec2), 'glyphs' => implode(':', $spec2)]
                ),
                'active' => $pBase['activespec']
            ),

            // completion lists: [subjectId => amount/timestamp/1]
            'skills'            => [],                      // skillId => [curVal, maxVal]
            'reputation'        => [],                      // factionId => curVal
            'titles'            => [],                      // titleId => 1
            'spells'            => [],                      // spellId => 1; recipes, pets, mounts
            'achievements'      => [],                      // achievementId => timestamp
            'quests'            => [],                      // questId => 1
            'achievementpoints' => 0,                       // max you have

            // UNKNOWN
            'statistics'        => [],                      // UNK all statistics?      [achievementId => killCount]
            'activity'          => [],                      // UNK recent achievements? [achievementId => killCount]
            'bookmarks'         => [2],                     // UNK pinned or claimed userId => profileIds..?
        );

        /* $profile[]
            'source'            => 2,                       // source: used if you create a profile from a genuine character. It inherites region, realm and bGroup
            'sourcename'        => 'SourceCharName',        //  >   if these three are false we get a 'genuine' profile [0 for genuine characters..?]
            'user'              => 1,                       //  >   'genuine' is the parameter for _isArmoryProfile(allowCustoms)   ['' for genuine characters..?]
            'username'          => 'TestUser',              //  >   also, if 'source' <> 0, the char-icon is requestet via profile.php?avatar
            'guild'             => 'GuildName',             // only on chars; id or null
            'description'       => 'this is a profile',     // only on custom profiles
            'arenateams'        => [],                      // [size(2|3|5) => DisplayName]; DisplayName gets urlized to use as link

            'customs'           => [],                      // custom profiles created from this char; profileId => [name, ownerId, iconString(optional)]
            'auras'             => [],                      // custom list of buffs, debuffs [spellId]

            // UNKNOWN
            'glyphs'            => [],                      // not really used .. i guess..?
            'pets'              => array(                   // UNK
                [],                                         // one array per pet, structure UNK
            ),
        */

        $completion = DB::Aowow()->select('SELECT type AS ARRAY_KEY, typeId AS ARRAY_KEY2, cur, max FROM ?_profiler_completion WHERE id = ?d', $pBase['id']);
        foreach ($completion as $type => $data)
        {
            switch ($type)
            {
                case TYPE_FACTION:                          // factionId => amount
                    $profile['reputation'] = array_combine(array_keys($data), array_column($data, 'cur'));
                    break;
                case TYPE_TITLE:
                    foreach ($data as &$d)
                        $d = 1;

                    $profile['titles'] = $data;
                    break;
                case TYPE_QUEST:
                    foreach ($data as &$d)
                        $d = 1;

                    $profile['quests'] = $data;
                    break;
                case TYPE_SPELL:
                    foreach ($data as &$d)
                        $d = 1;

                    $profile['spells'] = $data;
                    break;
                case TYPE_ACHIEVEMENT:
                    $profile['achievements']      = array_combine(array_keys($data), array_column($data, 'cur'));
                    $profile['achievementpoints'] = DB::Aowow()->selectCell('SELECT SUM(points) FROM ?_achievement WHERE id IN (?a)', array_keys($data));
                    break;
                case TYPE_SKILL:
                    foreach ($data as &$d)
                        $d = [$d['cur'], $d['max']];

                    $profile['skills'] = $data;
                    break;
            }
        }

        $buff = '';

        if ($items = DB::Aowow()->select('SELECT * FROM ?_profiler_items WHERE id = ?d', $pBase['id']))
        {
            $itemz = new ItemList(array(['id', array_column($items, 'item')], CFG_SQL_LIMIT_NONE));
            $data  = $itemz->getListviewData(ITEMINFO_JSON | ITEMINFO_SUBITEMS);

            foreach ($items as $i)
                $profile['inventory'][$i['slot']] = [$i['item'], $i['subItem'], $i['permEnchant'], $i['tempEnchant'], $i['gem1'], $i['gem2'], $i['gem3'], $i['gem4']];

            // get and apply inventory
            foreach ($itemz->iterate() as $iId => $__)
                $buff .= 'g_items.add('.$iId.', {name_'.User::$localeString.":'".Util::jsEscape($itemz->getField('name', true))."', quality:".$itemz->getField('quality').", icon:'".$itemz->getField('iconString')."', jsonequip:".Util::toJSON($data[$iId])."});\n";

            $buff .= "\n";
        }

        // if ($au = $char->getField('auras'))
        // {
            // $auraz = new SpellList(array(['id', $char->getField('auras')], CFG_SQL_LIMIT_NONE));
            // $dataz = $auraz->getListviewData();
            // $modz  = $auraz->getProfilerMods();

            // // get and apply aura-mods
            // foreach ($dataz as $id => $data)
            // {
                // $mods = [];
                // if (!empty($modz[$id]))
                // {
                    // foreach ($modz[$id] as $k => $v)
                    // {
                        // if (is_array($v))
                            // $mods[] = $v;
                        // else if ($str = @Game::$itemMods[$k])
                            // $mods[$str] = $v;
                    // }
                // }

                // $buff .= 'g_spells.add('.$id.", {id:".$id.", name:'".Util::jsEscape(mb_substr($data['name'], 1))."', icon:'".$data['icon']."', modifier:".Util::toJSON($mods)."});\n";
            // }
            // $buff .= "\n";
        // }

        /* depending on progress-achievements
            // required by progress in JScript move to handleLoad()?
            Util::$pageTemplate->extendGlobalIds(TYPE_NPC, [29120, 31134, 29306, 29311, 23980, 27656, 26861, 26723, 28923, 15991]);
        */

        // load available titles
        Util::loadStaticFile('p-titles-'.$pBase['gender'], $buff, true);

        // excludes; structure UNK type => [maskBit => [typeIds]] ?
        /*
            g_user.excludes = [type:[typeIds]]
            g_user.includes = [type:[typeIds]]
            g_user.excludegroups = groupMask        // requires g_user.settings != null

            maskBit are matched against fieldId from excludeGroups
            id: 1, label: LANG.dialog_notavail
            id: 2, label: LANG.dialog_tcg
            id: 4, label: LANG.dialog_collector
            id: 8, label: LANG.dialog_promo
            id: 16, label: LANG.dialog_nonus
            id: 96, label: LANG.dialog_faction
            id: 896, label: LANG.dialog_profession
            id: 1024, label: LANG.dialog_noexalted
        */
        // $buff .= "\n\ng_excludes = {};";

        // add profile to buffer
        $buff .= "\n\n\$WowheadProfiler.registerProfile(".Util::toJSON($profile, JSON_UNESCAPED_UNICODE).");"; // can't use JSON_NUMERIC_CHECK or the talent-string becomes a float

        return $buff."\n";
    }

    protected function checkId($val)
    {
        // expecting id-list
        if (preg_match('/\d+(,\d+)*/', $val))
            return array_map('intVal', explode(',', $val));

        return null;
    }

    protected function checkItems($val)
    {
        // expecting item-list
        if (preg_match('/\d+(:\d+)*/', $val))
            return array_map('intVal', explode(': ', $val));

        return null;
    }

}

?>
