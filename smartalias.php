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
            // Check if "Use ID Only" is enabled
            $useIdOnly = (int) $this->params->get('use_id_only', 0);
            
            // ถ้าใช้ ID อย่างเดียว และมี ID
            if ($useIdOnly && isset($article->id) && !empty($article->id)) {
                // Get the suffix from plugin parameters
                $suffix = $this->params->get('id_suffix', '');
                $separator = empty($suffix) ? '' : '-';
                $article->alias = $suffix . $separator . (string) $article->id;
                return;
            }

            // ปกป้อง alias เดิม - ย้ายไปหลังการตรวจสอบ useIdOnly
            if (!empty($article->alias)) {
                return;
            }
            
            // ส่วนที่เหลือของโค้ดเดิม...
            // Generate SEO-friendly alias from the article title
            $alias = OutputFilter::stringURLSafe($article->title);

            // Get the maximum alias length from plugin parameters
            $maxLength = (int) $this->params->get('alias_length', 100);

            // Limit the alias length if a maximum length is set
            if ($maxLength > 0 && mb_strlen($alias, 'UTF-8') > $maxLength) {
                $alias = mb_substr($alias, 0, $maxLength, 'UTF-8');
            }

            // Check if "Append ID" is enabled
            $appendId = (int) $this->params->get('append_id', 1);
            if ($appendId && !$isNew && isset($article->id)) {
                // Get ID position (prefix or suffix)
                $idPosition = $this->params->get('id_position', 'suffix');
                
                // Get separator (default: hyphen)
                $idSeparator = $this->params->get('id_separator', '-');
                
                // Create ID string
                $idStr = (string) $article->id;
                
                // Calculate available space for the title part
                if ($maxLength > 0) {
                    $idWithSepLength = mb_strlen($idStr . $idSeparator, 'UTF-8');
                    
                    if ($idPosition === 'prefix') {
                        // If ID is prefix, we need to truncate the title part if needed
                        if (mb_strlen($idStr . $idSeparator . $alias, 'UTF-8') > $maxLength) {
                            $titlePartLength = $maxLength - $idWithSepLength;
                            if ($titlePartLength > 0) {
                                $alias = mb_substr($alias, 0, $titlePartLength, 'UTF-8');
                            } else {
                                // Edge case: ID alone would exceed max length
                                $alias = mb_substr($idStr, 0, $maxLength, 'UTF-8');
                            }
                        }
                        // Combine ID and alias
                        $alias = $idStr . $idSeparator . $alias;
                    } else {
                        // If ID is suffix (default), truncate title to fit ID if needed
                        if (mb_strlen($alias . $idSeparator . $idStr, 'UTF-8') > $maxLength) {
                            $titlePartLength = $maxLength - $idWithSepLength;
                            if ($titlePartLength > 0) {
                                $alias = mb_substr($alias, 0, $titlePartLength, 'UTF-8');
                            } else {
                                // Edge case: ID alone would exceed max length
                                $alias = mb_substr($idStr, 0, $maxLength, 'UTF-8');
                            }
                        }
                        // Combine alias and ID
                        $alias = $alias . $idSeparator . $idStr;
                    }
                } else {
                    // No length limit, simply combine according to position
                    if ($idPosition === 'prefix') {
                        $alias = $idStr . $idSeparator . $alias;
                    } else {
                        $alias = $alias . $idSeparator . $idStr;
                    }
                }
            } else {
                // No ID to append, just limit the length if needed
                if ($maxLength > 0 && mb_strlen($alias, 'UTF-8') > $maxLength) {
                    $alias = mb_substr($alias, 0, $maxLength, 'UTF-8');
                }
            }

            $article->alias = $alias;
        }
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
            $clearAliasText = Text::_('PLG_SYSTEM_SMARTALIAS_CLEAR_ALIAS_BUTTON');
            $clearAliasConfirmText = Text::_('PLG_SYSTEM_SMARTALIAS_CLEAR_ALIAS_CONFIRM');
            $showCharCounter = (int) $this->params->get('show_char_counter', 1);
            
            // Determine the correct field ID based on the component
            $aliasFieldId = ($option === 'com_flexicontent') ? 'jform_alias' : 'jform_alias';
            
            // Add toggle button styles and new JavaScript
            $script = <<<JS
            window.addEventListener('load', function() {
                const aliasField = document.getElementById('{$aliasFieldId}');
                const aliasLabel = document.querySelector('label[for="{$aliasFieldId}"]');
                const titleField = document.getElementById('jform_title');
                const titleLabel = document.querySelector('label[for="jform_title"]');
                const charactersText = '{$charactersText}';
                let showCounter = {$showCharCounter};

                // Add toggle button for character counter
                const toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'btn btn-small';
                toggleBtn.innerHTML = '<span class="icon-eye"></span>';
                toggleBtn.style.marginLeft = '10px';
                titleLabel.parentNode.insertBefore(toggleBtn, titleLabel.nextSibling);
                
                // Add clear alias button
                if (aliasField && aliasLabel) {
                    // สร้างปุ่มล้าง alias
                    const clearBtn = document.createElement('button');
                    clearBtn.type = 'button';
                    clearBtn.className = 'btn btn-small btn-warning';
                    clearBtn.innerHTML = '{$clearAliasText}';
                    clearBtn.style.marginLeft = '10px';
                    
                    // สร้างฟังก์ชันอัพเดทตัวนับสำหรับ alias label
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
                        
                        // แทรกปุ่มล้าง alias หลังจากตัวนับ
                        aliasLabel.parentNode.insertBefore(clearBtn, aliasLabel.nextSibling);
                    }
                    
                    // ใช้ฟังก์ชันที่สร้างขึ้นเพื่ออัพเดทตัวนับและแทรกปุ่ม
                    updateAliasCounter();
                    
                    // อัพเดทตัวนับเมื่อมีการเปลี่ยนแปลงข้อความใน alias field
                    aliasField.addEventListener('input', updateAliasCounter);
                    
                    // Function to clear alias field
                    clearBtn.addEventListener('click', function() {
                        if (confirm('{$clearAliasConfirmText}')) {
                            aliasField.value = '';
                            // อัพเดทตัวนับหลังล้างข้อความ
                            updateAliasCounter();
                            
                            // Add visual confirmation
                            const notification = document.createElement('div');
                            notification.className = 'alert alert-success';
                            notification.innerHTML = '<span class="icon-check"></span> Alias cleared. Save to generate a new alias.';
                            notification.style.marginTop = '10px';
                            aliasField.parentNode.appendChild(notification);
                            
                            // Remove notification after 3 seconds
                            setTimeout(function() {
                                notification.remove();
                            }, 3000);
                        }
                    });
                }

                function updateCounter(field, label) {
                    if (!field || !label) return;
                    const length = field.value.length;
                    const originalText = label.getAttribute('data-original') || label.textContent;
                    label.textContent = showCounter 
                        ? originalText + ' (' + length + ' ' + charactersText + ')'
                        : originalText;
                }

                function toggleCounters() {
                    showCounter = !showCounter;
                    // อัพเดทตัวนับสำหรับ title
                    updateCounter(titleField, titleLabel);
                    
                    // อัพเดทตัวนับสำหรับ alias ด้วยฟังก์ชันที่สร้างขึ้นใหม่
                    if (aliasField && aliasLabel) {
                        updateAliasCounter();
                    }
                    
                    toggleBtn.innerHTML = showCounter 
                        ? '<span class="icon-eye"></span>' 
                        : '<span class="icon-eye-close"></span>';
                }

                if (titleField && titleLabel) {
                    titleLabel.setAttribute('data-original', titleLabel.textContent);
                    updateCounter(titleField, titleLabel);
                    titleField.addEventListener('input', () => updateCounter(titleField, titleLabel));
                }

                toggleBtn.addEventListener('click', toggleCounters);
            });
            JS;

            $buffer = str_replace('</body>', "<script>{$script}</script></body>", $buffer);
            $app->setBody($buffer);
        }
    }
}
