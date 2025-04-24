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
        // ตรวจสอบว่าเป็นบทความหรือไม่
        if (($context === 'com_content.article' || $context === 'com_flexicontent.item') && isset($article->title)) {
            $app = Factory::getApplication();
            
            // ดึงค่าพารามิเตอร์
            $params = $this->getPluginParams();
            $maxLength = (int) $params->get('alias_length', 100);
            $appendId = (int) $params->get('append_id', 1);
            $useIdOnly = (int) $params->get('use_id_only', 0);
            
            // ตรวจสอบความยาวของ alias ที่มีอยู่ 
            $aliasNeedsProcessing = false;
            
            if (!empty($article->alias)) {
                // ถ้ามี alias อยู่แล้ว แต่เป็นโหมด ID only ให้ทำงานต่อ
                if ($appendId && $useIdOnly && isset($article->id) && !empty($article->id)) {
                    $aliasNeedsProcessing = true;
                } 
                // ตรวจสอบความยาว alias ที่มีอยู่
                else if ($maxLength > 0 && mb_strlen($article->alias, 'UTF-8') > $maxLength) {
                    $aliasNeedsProcessing = true;
                }
                // ตรวจสอบว่ามี ID หรือไม่
                else if ($appendId && !$isNew && isset($article->id) && !empty($article->id)) {
                    // ตรวจสอบว่า alias ปัจจุบันมี ID อยู่หรือไม่
                    $idStr = (string) $article->id;
                    $hasId = (strpos($article->alias, $idStr) !== false);
                    
                    if (!$hasId) {
                        $aliasNeedsProcessing = true;
                    }
                }
                
                // ถ้าไม่ต้องทำอะไรกับ alias ให้ออกจากฟังก์ชัน
                if (!$aliasNeedsProcessing) {
                    return;
                }
            }
            
            // กรณี 1: ใช้ ID อย่างเดียว
            if ($appendId && $useIdOnly && isset($article->id) && !empty($article->id)) {
                $this->applyIdOnlyAlias($article, $params);
                return;
            }
            
            // กรณี 2: สร้าง alias จากชื่อบทความ + ตัดความยาว + อาจเพิ่ม ID
            
            // ถ้ามี alias อยู่แล้ว ใช้ alias ที่มีอยู่เป็นฐาน แทนที่จะสร้างใหม่จากชื่อเรื่อง
            if (!empty($article->alias) && $aliasNeedsProcessing) {
                $this->processExistingAlias($article, $params, $isNew);
            } else {
                $this->generateNormalAlias($article, $params, $isNew);
            }
        }
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
        
        // ดึงค่า ID Suffix (คำนำหน้า ID)
        $suffix = $params->get('id_suffix', '');
        
        // ตรวจสอบว่า suffix มีเฉพาะตัวอักษรภาษาอังกฤษหรือไม่
        if (!empty($suffix) && !preg_match('/^[a-zA-Z0-9_-]+$/', $suffix)) {
            if ($app->isClient('administrator')) {
                $app->enqueueMessage("คำนำหน้า ID ต้องเป็นตัวอักษรภาษาอังกฤษเท่านั้น", 'warning');
            }
            $suffix = '';
        }
        
        // กำหนดตัวคั่นเป็น - เสมอเมื่อมี suffix
        $separator = empty($suffix) ? '' : '-';
        
        // สร้าง alias แบบ ID only
        $article->alias = $suffix . $separator . (string) $article->id;
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
     * ประมวลผล alias ที่มีอยู่แล้ว (ตัดความยาวหรือเพิ่ม ID)
     */
    private function processExistingAlias($article, $params, $isNew)
    {
        $app = Factory::getApplication();
        $maxLength = (int) $params->get('alias_length', 100);
        $appendId = (int) $params->get('append_id', 1);
        
        // เก็บ alias เดิม
        $alias = $article->alias;
        
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
            if (strpos($alias, $idStr) === false) {
                $idPosition = $params->get('id_position', 'suffix');
                $idSeparator = '-';
                
                if ($idPosition === 'prefix') {
                    $alias = $idStr . $idSeparator . $alias;
                } else {
                    $alias = $alias . $idSeparator . $idStr;
                }
            }
        }
        
        // กำหนดค่า alias ให้กับบทความ
        $article->alias = $alias;
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
