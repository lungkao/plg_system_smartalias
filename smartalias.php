<?php

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;

class PlgSystemSmartalias extends CMSPlugin
{
    protected $autoloadLanguage = true;

    public function onContentBeforeSave($context, $article, $isNew)
    {
        // ถ้าไม่ใช่บทความหรือ item ใน FlexiContent ให้ออกจากฟังก์ชัน
        if (($context !== 'com_content.article' && $context !== 'com_flexicontent.item') || !isset($article->title)) {
            return true;
        }
        
        // โหลดพารามิเตอร์
        $params = $this->params;
        $forceWhenEmpty = (int) $params->get('force_alias_when_empty', 1);
        
        // ตรวจสอบเงื่อนไขการสร้าง alias ใหม่
        $needNewAlias = $isNew || empty($article->alias);
        
        // ถ้าเปิดใช้งานการบังคับสร้าง alias เมื่อช่องว่าง ให้ตรวจสอบว่า field ว่างหรือไม่
        if (!$needNewAlias && $forceWhenEmpty) {
            // ตรวจสอบหา alias ในการส่งข้อมูลฟอร์ม
            $jinput = Factory::getApplication()->input;
            $formData = $jinput->get('jform', array(), 'array');
            
            // ตรวจสอบให้แน่ใจว่าเฉพาะเมื่อผู้ใช้ส่งฟอร์มมาด้วยช่อง alias ว่างเปล่า
            // แต่ใน $article->alias มีค่า (ค่าจาก DB) คือ ผู้ใช้ตั้งใจล้างช่อง alias
            if (isset($formData['alias']) && $formData['alias'] === '' && !empty($article->alias)) {
                $needNewAlias = true;
                $article->alias = ''; // กำหนดให้ alias ว่างเพื่อให้ระบบสร้างใหม่
            }
        }
        
        if ($needNewAlias) {
            // สร้าง alias จากชื่อบทความ
            $this->createAliasFromTitle($article, $params);
        } else {
            // ถ้าเป็นบทความที่มีอยู่แล้วและ alias ไม่ว่าง ให้ประมวลผลเฉพาะการเพิ่ม ID (ถ้าตั้งค่าไว้)
            // แต่ไม่ต้องตัดความยาวหรือสร้าง alias ใหม่
            $this->processExistingAlias($article, $params, $isNew);
        }
        
        return true;
    }

    /**
     * ดึงค่าพารามิเตอร์ของปลั๊กอินจากฐานข้อมูล
     */
    private function getPluginParams()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('smartalias'));
        $db->setQuery($query);
        $rawParams = $db->loadResult();
        return new \Joomla\Registry\Registry($rawParams);
    }

    /**
     * สร้าง alias โดยใช้ ID อย่างเดียว
     */
    private function applyIdOnlyAlias($article, $params)
    {
        $app = Factory::getApplication();
        
        // ดึงค่า ID Prefix (คำนำหน้า ID)
        $prefix = $params->get('id_prefix', '');

        // ตรวจสอบว่า prefix มีเฉพาะตัวอักษรภาษาอังกฤษหรือไม่
        if (!empty($prefix) && !preg_match('/^[a-zA-Z0-9_-]+$/', $prefix)) {
            if ($app->isClient('administrator')) {
                $app->enqueueMessage("คำนำหน้า ID ต้องเป็นตัวอักษรภาษาอังกฤษเท่านั้น", 'warning');
            }
            $prefix = '';
        }

        // กำหนดตัวคั่นเป็น - เสมอเมื่อมี prefix
        $separator = empty($prefix) ? '' : '-';

        // สร้าง alias แบบ ID only
        $article->alias = $prefix . $separator . (string) $article->id;
    }

    /**
     * สร้าง alias ปกติ + ตัดความยาว + อาจเพิ่ม ID
     */
    private function generateNormalAlias($article, $params, $isNew)
    {
        $app = Factory::getApplication();
        $maxLength = (int) $params->get('alias_length', 100);
        $appendId = (int) $params->get('append_id', 1);
        
        // สร้าง alias จากชื่อบทความ
        $alias = OutputFilter::stringURLSafe($article->title);
        
        // ตัดความยาวของ alias
        if ($maxLength > 0) {
            $originalLength = mb_strlen($alias, 'UTF-8');
            if ($originalLength > $maxLength) {
                $alias = mb_substr($alias, 0, $maxLength, 'UTF-8');
            }
        }
        
        // เพิ่ม ID ถ้าเปิดใช้งานและเป็นบทความเดิม
        if ($appendId && !$isNew && isset($article->id) && !empty($article->id)) {
            $idPosition = $params->get('id_position', 'suffix');
            // บังคับใช้ - เป็นตัวคั่น
            $idSeparator = '-';
            $idStr = (string) $article->id;
            
            if ($idPosition === 'prefix') {
                $alias = $idStr . $idSeparator . $alias;
            } else {
                $alias = $alias . $idSeparator . $idStr;
            }
        }
        
        // กำหนดค่า alias ให้กับบทความ
        $article->alias = $alias;
    }

    /**
     * สร้าง alias จากชื่อบทความ
     *
     * @param   object  $article  ออบเจ็กต์บทความ
     * @param   object  $params   พารามิเตอร์ของปลั๊กอิน
     * @return  void
     */
    private function createAliasFromTitle($article, $params)
    {
        // ชื่อบทความ
        $title = $article->title;

        // สร้าง alias จาก title
        $alias = OutputFilter::stringURLSafe($title);

        // ถ้า alias ว่างเปล่า ให้ใช้วันที่ปัจจุบันแทน
        if (empty($alias)) {
            $alias = Factory::getDate()->format('Y-m-d-H-i-s');
        }

        // ตัดความยาวและเพิ่ม ID (ถ้าตั้งค่าไว้)
        $alias = $this->processAliasLength($alias, $params, $article, false);

        // กำหนด alias ให้บทความ
        $article->alias = $alias;
    }

    /**
     * ประมวลผล alias ที่มีอยู่แล้ว
     *
     * @param   object  $article  ออบเจ็กต์บทความ
     * @param   object  $params   พารามิเตอร์ของปลั๊กอิน
     * @param   bool    $isNew    เป็นบทความใหม่หรือไม่
     * @return  void
     */
    private function processExistingAlias($article, $params, $isNew)
    {
        // ตรวจสอบว่าเป็นบทความใหม่หรือไม่ ถ้าเป็นบทความที่มีอยู่แล้ว
        // และไม่ใช่ระบบที่สร้าง alias เอง ให้เคารพค่าที่ผู้ใช้กำหนด
        $jinput = Factory::getApplication()->input;
        $formData = $jinput->get('jform', array(), 'array');
        
        // ถ้าผู้ใช้กำหนด alias เอง ให้ใช้ค่านั้นโดยไม่เปลี่ยนแปลง
        if (isset($formData['alias']) && !empty($formData['alias'])) {
            // ใช้ค่า alias ที่ผู้ใช้กำหนดเอง โดยไม่มีการแก้ไขใดๆ
            // ทั้งไม่ตัดความยาวและไม่เพิ่ม ID
            return;
        }
        
        // เฉพาะกรณีที่ alias มาจากระบบเท่านั้น จึงจะมีการประมวลผลต่อไปนี้
        $appendId = (int) $params->get('append_id', 1);
        $useIdOnly = (int) $params->get('use_id_only', 0);
        
        // ถ้าตั้งค่าให้ใช้ ID อย่างเดียว และมี ID บทความ
        if ($appendId && $useIdOnly && !$isNew && isset($article->id) && !empty($article->id)) {
            // ดึงค่า id_prefix
            $idPrefix = $params->get('id_prefix', '');
            
            // ตรวจสอบ ID Prefix (ต้องเป็นภาษาอังกฤษ ตัวเลข และเครื่องหมายขีด)
            if (!empty($idPrefix) && !preg_match('/^[a-zA-Z0-9\-_]+$/', $idPrefix)) {
                $idPrefix = '';
            }
            
            // พิมพ์ค่าเพื่อดีบัก (นำออกเมื่อแก้ไขเสร็จ)
            error_log('Debug - ID Prefix: ' . $idPrefix);
            error_log('Debug - Article ID: ' . $article->id);
            
            // กำหนด alias เป็น ID อย่างเดียว (อาจมีคำนำหน้า)
            if (!empty($idPrefix)) {
                $article->alias = $idPrefix . '-' . $article->id;
            } else {
                $article->alias = (string) $article->id;
            }
            
            // พิมพ์ค่าเพื่อดีบัก (นำออกเมื่อแก้ไขเสร็จ)
            error_log('Debug - Final alias: ' . $article->alias);
            return;
        }
    }

    /**
     * ตัดความยาว alias และเพิ่ม ID ตามการตั้งค่า
     *
     * @param   string  $alias    alias ที่ต้องการประมวลผล
     * @param   object  $params   พารามิเตอร์ของปลั๊กอิน
     * @param   object  $article  ออบเจ็กต์บทความ
     * @param   bool    $isNew    เป็นบทความใหม่หรือไม่
     * @return  string  alias ที่ประมวลผลแล้ว
     */
    private function processAliasLength($alias, $params, $article, $isNew)
    {
        // ดึงค่าพารามิเตอร์
        $maxLength = (int) $params->get('alias_length', 100);
        $appendId = (int) $params->get('append_id', 1);
        $idPosition = $params->get('id_position', 'suffix');
        $useIdOnly = (int) $params->get('use_id_only', 0);
        $idPrefix = $params->get('id_prefix', '');

        // ถ้าใช้เฉพาะ ID และบทความไม่ใช่บทความใหม่
        if ($appendId && $useIdOnly && !$isNew && isset($article->id) && !empty($article->id)) {
            // ตรวจสอบ ID Prefix (ต้องเป็นภาษาอังกฤษ ตัวเลข และเครื่องหมายขีด)
            if (!empty($idPrefix) && !preg_match('/^[a-zA-Z0-9\-_]+$/', $idPrefix)) {
                // กำหนดค่าเริ่มต้นใหม่ถ้ามีอักขระไม่ถูกต้อง
                $idPrefix = '';
            }
            
            // กำหนด alias เป็น ID อย่างเดียว (อาจมีคำนำหน้า)
            if (!empty($idPrefix)) {
                return $idPrefix . '-' . $article->id;
            } else {
                return (string) $article->id;
            }
        }
        
        // ตัดความยาวของ alias ถ้าจำเป็น
        if ($maxLength > 0) {
            $originalLength = mb_strlen($alias, 'UTF-8');
            
            // ถ้าจะเพิ่ม ID และไม่ใช่บทความใหม่ ให้คำนวณพื้นที่สำหรับ ID
            $idSpace = 0;
            if ($appendId && !$isNew && isset($article->id) && !empty($article->id)) {
                $idStr = (string) $article->id;
                $idSpace = mb_strlen($idStr, 'UTF-8') + 1; // +1 สำหรับตัวคั่น (-)
            }
            
            // ตัดความยาวโดยคำนึงถึงพื้นที่สำหรับ ID
            if ($originalLength > ($maxLength - $idSpace) && $maxLength > $idSpace) {
                $alias = mb_substr($alias, 0, $maxLength - $idSpace, 'UTF-8');
            }
        }
        
        // เพิ่ม ID ถ้าเปิดใช้งานและเป็นบทความเดิม
        if ($appendId && !$isNew && isset($article->id) && !empty($article->id)) {
            // ตรวจสอบว่ามี ID อยู่แล้วหรือไม่
            $idStr = (string) $article->id;
            $idPattern = preg_quote($idStr, '/');
            
            // ถ้ายังไม่มี ID ในตำแหน่งที่ต้องการ ให้เพิ่ม ID
            if ($idPosition === 'prefix') {
                // ตรวจสอบว่ามี ID นำหน้าอยู่แล้วหรือไม่
                if (!preg_match('/^' . $idPattern . '\-/', $alias)) {
                    $alias = $idStr . '-' . $alias;
                }
            } else {
                // ตรวจสอบว่ามี ID ต่อท้ายอยู่แล้วหรือไม่
                if (!preg_match('/\-' . $idPattern . '$/', $alias)) {
                    $alias = $alias . '-' . $idStr;
                }
            }
        }
        
        return $alias;
    }

    public function onAfterRender()
    {
        $app = Factory::getApplication();

        // เพิ่มเงื่อนไขเพื่อรองรับ Flexicontent
        $option = $app->input->get('option');
        $view = $app->input->get('view');
        
        $supportedCombinations = [
            // Joomla Core Articles
            ['option' => 'com_content', 'view' => 'article'],
            // Flexicontent Items
            ['option' => 'com_flexicontent', 'view' => 'item']
        ];
        
        $isSupported = false;
        foreach ($supportedCombinations as $combo) {
            if ($option === $combo['option'] && $view === $combo['view']) {
                $isSupported = true;
                break;
            }
        }

        if ($app->isClient('administrator') && $isSupported) {
            $lang = Factory::getLanguage();
            $lang->load('plg_system_smartalias', JPATH_ADMINISTRATOR);
            
            $buffer = $app->getBody();
            
            $charactersText = Text::_('PLG_SYSTEM_SMARTALIAS_CHARACTERS');
            $showCharCounter = (int) $this->params->get('show_char_counter', 1);
            
            // Determine the correct field ID based on the component
            $aliasFieldId = ($option === 'com_flexicontent') ? 'jform_alias' : 'jform_alias';
            
            // Add JavaScript
            $script = <<<JS
            window.addEventListener('load', function() {
                const aliasField = document.getElementById('{$aliasFieldId}');
                const aliasLabel = document.querySelector('label[for="{$aliasFieldId}"]');
                const titleField = document.getElementById('jform_title');
                const titleLabel = document.querySelector('label[for="jform_title"]');
                const charactersText = '{$charactersText}';
                let showCounter = {$showCharCounter}; // ยังคงใช้ตัวแปรนี้เพื่อควบคุมการแสดงตัวนับตาม plugin setting
                
                // สำหรับช่อง Alias เพียงแสดงตัวนับอักขระ (ไม่มีปุ่มล้าง)
                if (aliasField && aliasLabel && showCounter) {
                    function updateAliasCounter() {
                        if (!aliasField || !aliasLabel) return;
                        const length = aliasField.value.length;
                        const originalText = aliasLabel.getAttribute('data-original') || aliasLabel.textContent;
                        
                        // เก็บตัวอ้างอิงถึง original text เพื่อใช้ในการอัพเดทภายหลัง
                        if (!aliasLabel.getAttribute('data-original')) {
                            aliasLabel.setAttribute('data-original', originalText);
                        }
                        
                        // ล้างข้อความที่อาจมีอยู่เดิม
                        aliasLabel.textContent = originalText;
                        
                        // เพิ่มตัวนับเข้าไป ถ้าเปิดใช้งาน
                        if (showCounter) {
                            const counterSpan = document.createElement('span');
                            counterSpan.textContent = ' (' + length + ' ' + charactersText + ')';
                            counterSpan.className = 'char-counter';
                            aliasLabel.appendChild(counterSpan);
                        }
                    }
                    
                    // ใช้ฟังก์ชันที่สร้างขึ้นเพื่ออัพเดทตัวนับ
                    updateAliasCounter();
                    
                    // อัพเดทตัวนับเมื่อมีการเปลี่ยนแปลงข้อความใน alias field
                    aliasField.addEventListener('input', updateAliasCounter);
                }

                // สำหรับช่อง Title ยังคงแสดงตัวนับตามการตั้งค่า
                if (titleField && titleLabel && showCounter) {
                    function updateTitleCounter() {
                        if (!titleField || !titleLabel) return;
                        const length = titleField.value.length;
                        const originalText = titleLabel.getAttribute('data-original') || titleLabel.textContent;
                        
                        // เก็บตัวอ้างอิงถึง original text เพื่อใช้ในการอัพเดทภายหลัง
                        if (!titleLabel.getAttribute('data-original')) {
                            titleLabel.setAttribute('data-original', originalText);
                        }
                        
                        titleLabel.textContent = originalText + ' (' + length + ' ' + charactersText + ')';
                    }

                    updateTitleCounter();
                    titleField.addEventListener('input', updateTitleCounter);
                }
            });
            JS;

            $buffer = str_replace('</body>', "<script>{$script}</script></body>", $buffer);
            $app->setBody($buffer);
        }
    }
}
