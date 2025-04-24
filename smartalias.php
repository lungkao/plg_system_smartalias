<?php

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;

class PlgSystemSmartalias extends CMSPlugin
{
    protected $autoloadLanguage = true; // เพิ่มบรรทัดนี้เพื่อโหลดไฟล์ภาษาอัตโนมัติ

    public function onContentBeforeSave($context, $article, $isNew)
    {
        if ($context === 'com_content.article' && isset($article->title)) {
            // ปกป้อง alias เดิม
            if (!empty($article->alias)) {
                // นับจำนวนตัวอักษรของ alias เดิม
                $aliasLength = mb_strlen($article->alias, 'UTF-8');
                Factory::getApplication()->enqueueMessage("Current alias length: {$aliasLength}");
                return;
            }

            // Check if "Use ID Only" is enabled
            $useIdOnly = (int) $this->params->get('use_id_only', 0);
            if ($useIdOnly) {
                // Get the suffix from plugin parameters
                $suffix = $this->params->get('id_suffix', '');
                $article->alias = $suffix . '-' . (string) $article->id;

                // นับจำนวนตัวอักษรของ alias ที่สร้างใหม่
                $aliasLength = mb_strlen($article->alias, 'UTF-8');
                Factory::getApplication()->enqueueMessage("Generated alias length: {$aliasLength}");
                return;
            }

            // Generate SEO-friendly alias from the article title
            $alias = OutputFilter::stringURLSafe($article->title);

            // Get the maximum alias length from plugin parameters
            $maxLength = (int) $this->params->get('alias_length', 100);

            // Limit the alias length if a maximum length is set
            if ($maxLength > 0) {
                $alias = mb_substr($alias, 0, $maxLength, 'UTF-8');
            }

            // Check if "Append ID" is enabled
            $appendId = (int) $this->params->get('append_id', 1);
            if ($appendId && !$isNew && isset($article->id)) {
                $alias .= '-' . $article->id;
            }

            // นับจำนวนตัวอักษรของ alias ที่สร้างใหม่
            if (isset($alias)) {
                $aliasLength = mb_strlen($alias, 'UTF-8');
                Factory::getApplication()->enqueueMessage("Generated alias length: {$aliasLength}");
            } else {
                $aliasLength = 0; // กำหนดค่าเริ่มต้นในกรณีที่ $alias ไม่ถูกตั้งค่า
            }

            $article->alias = $alias;
        }
    }

    public function onAfterRender()
    {
        $app = Factory::getApplication();

        if ($app->isClient('administrator') && $app->input->get('option') === 'com_content' && $app->input->get('view') === 'article') {
            $lang = Factory::getLanguage();
            $lang->load('plg_system_smartalias', JPATH_ADMINISTRATOR);
            
            $buffer = $app->getBody();
            
            $charactersText = Text::_('PLG_SYSTEM_SMARTALIAS_CHARACTERS');
            $clearAliasText = Text::_('PLG_SYSTEM_SMARTALIAS_CLEAR_ALIAS_BUTTON');
            $clearAliasConfirmText = Text::_('PLG_SYSTEM_SMARTALIAS_CLEAR_ALIAS_CONFIRM');
            $showCharCounter = (int) $this->params->get('show_char_counter', 1);
            
            // Add toggle button styles and new JavaScript
            $script = <<<JS
            window.addEventListener('load', function() {
                const aliasField = document.getElementById('jform_alias');
                const aliasLabel = document.querySelector('label[for="jform_alias"]');
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
