jQuery(document).ready(function($) {
    
    // Add Nano Banana button to images in the editor
    function addNanoBananaButton() {
        // For Media Library
        addMediaLibrarySupport();
        
        // For Post Editor (both Gutenberg and Classic)
        addPostEditorSupport();
        
        // For Gutenberg editor (simplified approach)
        addGutenbergSupport();
        
        // For Classic editor
        addClassicEditorSupport();
    }
    
    function addPostEditorSupport() {
        // Check if we're in post editor
        if (window.location.href.includes('post.php') || window.location.href.includes('post-new.php')) {
            
            // For Gutenberg blocks
            $(document).on('click', '.wp-block-image', function() {
                const $block = $(this);
                const $img = $block.find('img');
                const imageId = getImageIdFromGutenbergImage($img);
                
                if (imageId && !$block.find('.nano-banana-block-btn').length) {
                    setTimeout(function() {
                        addBlockEditButton($block, imageId);
                    }, 100);
                }
            });
            
            // For Classic Editor images
            $(document).on('click', '.mce-content-body img', function() {
                const img = this;
                const imageId = getImageIdFromClassicEditor(img);
                
                if (imageId) {
                    setTimeout(function() {
                        showEditDialog(imageId);
                    }, 100);
                }
            });
        }
    }
    
    function addBlockEditButton($block, imageId) {
        // Add floating button to Gutenberg image block
        const button = $('<button>')
            .addClass('nano-banana-block-btn components-button')
            .attr('data-image-id', imageId)
            .html('✨ Edit')
            .css({
                'position': 'absolute',
                'top': '8px',
                'right': '8px',
                'z-index': '10',
                'background': 'rgba(0, 0, 0, 0.8)',
                'color': 'white',
                'border': 'none',
                'border-radius': '3px',
                'padding': '4px 8px',
                'font-size': '11px',
                'cursor': 'pointer'
            })
            .click(function(e) {
                e.preventDefault();
                e.stopPropagation();
                showEditDialog(imageId);
            });
        
        $block.css('position', 'relative').append(button);
        
        // Remove button when block is deselected
        setTimeout(function() {
            if (!$block.hasClass('is-selected')) {
                button.remove();
            }
        }, 5000);
    }
    
    function addMediaLibrarySupport() {
        // Handle media library edit buttons that are already rendered
        $(document).on('click', '.nano-banana-edit-btn', function(e) {
            e.preventDefault();
            const imageId = $(this).data('image-id');
            if (imageId) {
                showEditDialog(imageId);
            }
        });
        
        // Add buttons to attachment details when media modal opens
        $(document).on('DOMNodeInserted', function(e) {
            if ($(e.target).hasClass('attachment-details') || $(e.target).find('.attachment-details').length) {
                setTimeout(function() {
                    $('.attachment-details').each(function() {
                        const $details = $(this);
                        if (!$details.find('.nano-banana-edit-btn').length) {
                            // Try to get image ID from various sources
                            const imageId = getImageIdFromAttachmentDetails($details);
                            if (imageId) {
                                addNanoBananaButtonToDetails($details, imageId);
                            }
                        }
                    });
                }, 100);
            }
        });
        
        // Handle clicks on media library images
        $(document).on('click', '.attachment-preview img', function() {
            const $img = $(this);
            const imageId = getImageIdFromMediaLibrary($img);
            if (imageId && !$img.siblings('.nano-banana-overlay').length) {
                addOverlayButton($img, imageId);
            }
        });
    }
    
    function getImageIdFromAttachmentDetails($details) {
        // Method 1: Check for data attributes
        let imageId = $details.data('id') || $details.attr('data-id');
        if (imageId) return imageId;
        
        // Method 2: Look for attachment ID in URLs or other elements
        const $urlInput = $details.find('input[value*="wp-content/uploads"]');
        if ($urlInput.length) {
            const url = $urlInput.val();
            // This would need server-side lookup - for now, try to extract from DOM
            return extractImageIdFromContext($details);
        }
        
        return null;
    }
    
    function getImageIdFromMediaLibrary($img) {
        // Method 1: Check parent elements for attachment data
        const $attachment = $img.closest('.attachment');
        if ($attachment.length) {
            return $attachment.data('id') || $attachment.attr('data-id');
        }
        
        // Method 2: Check for media frame context
        const $li = $img.closest('li');
        if ($li.length && $li.attr('data-id')) {
            return $li.attr('data-id');
        }
        
        return null;
    }
    
    function extractImageIdFromContext($context) {
        // Look for any element with attachment ID
        const $idElements = $context.find('[data-id]');
        if ($idElements.length) {
            return $idElements.first().data('id');
        }
        
        // Try to find ID in classes (wp-image-123)
        const classNames = $context.attr('class') || '';
        const match = classNames.match(/wp-image-(\d+)/);
        if (match) {
            return match[1];
        }
        
        return null;
    }
    
    function addNanoBananaButtonToDetails($details, imageId) {
        const button = $('<button>')
            .addClass('button nano-banana-edit-btn')
            .text('✨ Edit with Nano Banana')
            .attr('data-image-id', imageId)
            .css('margin-top', '10px');
        
        // Add button to the end of the details
        $details.find('.settings').append(button);
    }
    
    function addOverlayButton($img, imageId) {
        const overlay = $('<div>')
            .addClass('nano-banana-overlay')
            .css({
                'position': 'absolute',
                'top': '5px',
                'right': '5px',
                'z-index': '999'
            });
        
        const button = $('<button>')
            .addClass('button button-small nano-banana-edit-btn')
            .text('✨ Edit')
            .attr('data-image-id', imageId)
            .css({
                'font-size': '11px',
                'padding': '2px 6px'
            });
        
        overlay.append(button);
        $img.parent().css('position', 'relative').append(overlay);
    }
    
    function addGutenbergSupport() {
        // Add button to Gutenberg inspector panel when image block is selected
        $(document).on('click', '.wp-block-image', function() {
            const $block = $(this);
            const $img = $block.find('img');
            const imageId = getImageIdFromGutenbergImage($img);
            
            if (imageId) {
                setTimeout(function() {
                    addToGutenbergInspector(imageId);
                }, 200);
            }
        });
        
        // Also listen for block selection changes
        if (typeof wp !== 'undefined' && wp.data) {
            // Watch for selected block changes
            let lastSelectedBlock = null;
            setInterval(function() {
                if (wp.data.select('core/block-editor')) {
                    const selectedBlock = wp.data.select('core/block-editor').getSelectedBlock();
                    
                    if (selectedBlock && selectedBlock !== lastSelectedBlock) {
                        if (selectedBlock.name === 'core/image' && selectedBlock.attributes.id) {
                            setTimeout(function() {
                                addToGutenbergInspector(selectedBlock.attributes.id);
                            }, 300);
                        }
                        lastSelectedBlock = selectedBlock;
                    }
                }
            }, 500);
        }
    }
    
    function addToGutenbergInspector(imageId) {
        // Add button to the block inspector panel (right sidebar)
        const $inspector = $('.block-editor-block-inspector, .editor-sidebar');
        
        if ($inspector.length && !$inspector.find('.nano-banana-inspector-btn').length) {
            // Create a panel section for our button
            const panelSection = $('<div>')
                .addClass('components-panel__body nano-banana-panel')
                .css({
                    'border-top': '1px solid #e2e4e7',
                    'padding': '16px'
                });
            
            const panelTitle = $('<h2>')
                .addClass('components-panel__body-title')
                .html('<button type="button" class="components-button components-panel__body-toggle" style="width: 100%; text-align: left; padding: 0; background: none; border: none;">AI Image Editing</button>');
            
            const panelContent = $('<div>')
                .addClass('components-panel__body-content')
                .css('margin-top', '12px');
            
            const editButton = $('<button>')
                .addClass('components-button is-secondary nano-banana-inspector-btn')
                .attr('data-image-id', imageId)
                .html('✨ Edit with Nano Banana AI')
                .css({
                    'width': '100%',
                    'justify-content': 'center',
                    'margin-bottom': '8px'
                })
                .click(function(e) {
                    e.preventDefault();
                    showEditDialog(imageId);
                });
            
            const description = $('<p>')
                .css({
                    'font-size': '12px',
                    'color': '#757575',
                    'margin': '8px 0 0 0'
                })
                .text('Use AI to modify this image with natural language prompts.');
            
            panelContent.append(editButton).append(description);
            panelSection.append(panelTitle).append(panelContent);
            
            // Insert after the image settings panel
            const $imagePanel = $inspector.find('[class*="image"], .components-panel__body').last();
            if ($imagePanel.length) {
                $imagePanel.after(panelSection);
            } else {
                $inspector.append(panelSection);
            }
        }
        
        // Remove old buttons from other blocks
        $('.nano-banana-panel').each(function() {
            const $panel = $(this);
            const buttonImageId = $panel.find('.nano-banana-inspector-btn').data('image-id');
            if (buttonImageId != imageId) {
                $panel.remove();
            }
        });
    }
    
    function getImageIdFromGutenbergImage($img) {
        // Method 1: Check image data attributes
        let imageId = $img.data('id') || $img.attr('data-id');
        if (imageId) return imageId;
        
        // Method 2: Check parent block for wp-image class
        const $block = $img.closest('.wp-block-image');
        if ($block.length) {
            const classes = $block.attr('class') || '';
            const match = classes.match(/wp-image-(\d+)/);
            if (match) return match[1];
        }
        
        // Method 3: Check img element classes
        const imgClasses = $img.attr('class') || '';
        const imgMatch = imgClasses.match(/wp-image-(\d+)/);
        if (imgMatch) return imgMatch[1];
        
        return null;
    }
    
    function addClassicEditorSupport() {
        // Method 1: Add button to images in TinyMCE editor
        $(document).on('click', '#content_ifr', function() {
            setTimeout(function() {
                const iframe = document.getElementById('content_ifr');
                if (iframe && iframe.contentDocument) {
                    const images = iframe.contentDocument.querySelectorAll('img');
                    images.forEach(function(img) {
                        const imageId = getImageIdFromClassicEditor(img);
                        if (imageId && !img.nextElementSibling?.classList.contains('nano-banana-edit-btn')) {
                            addEditButtonToClassicEditor(img, imageId);
                        }
                    });
                }
            }, 500);
        });
        
        // Method 2: Hook into WordPress media modal for Classic Editor
        if (typeof wp !== 'undefined' && wp.media) {
            // Override media frame to add our button
            const originalMediaFrame = wp.media.view.MediaFrame.Post;
            wp.media.view.MediaFrame.Post = originalMediaFrame.extend({
                initialize: function() {
                    originalMediaFrame.prototype.initialize.apply(this, arguments);
                    this.on('content:activate:browse', this.addNanoBananaToMediaFrame, this);
                },
                
                addNanoBananaToMediaFrame: function() {
                    const self = this;
                    setTimeout(function() {
                        addNanoBananaToSelectedMedia(self);
                    }, 500);
                }
            });
        }
    }
    
    function addNanoBananaToSelectedMedia(mediaFrame) {
        // Add button to media selection sidebar
        const $sidebar = $('.media-sidebar, .attachment-details');
        if ($sidebar.length) {
            $sidebar.each(function() {
                const $details = $(this);
                if (!$details.find('.nano-banana-media-btn').length) {
                    const imageId = getImageIdFromMediaSidebar($details);
                    if (imageId) {
                        const button = $('<button>')
                            .addClass('button nano-banana-media-btn')
                            .attr('data-image-id', imageId)
                            .html('✨ Edit with Nano Banana AI')
                            .css('margin', '10px 0')
                            .click(function(e) {
                                e.preventDefault();
                                showEditDialog(imageId);
                            });
                        
                        $details.find('.settings, .attachment-info').first().append(button);
                    }
                }
            });
        }
    }
    
    function getImageIdFromMediaSidebar($sidebar) {
        // Try multiple methods to get image ID from media sidebar
        
        // Method 1: Data attributes
        let imageId = $sidebar.data('id') || $sidebar.attr('data-id');
        if (imageId) return imageId;
        
        // Method 2: Hidden inputs
        const $idInput = $sidebar.find('input[name*="id"], input[value*="attachment_"]');
        if ($idInput.length) {
            const value = $idInput.val();
            const match = value.match(/\d+/);
            if (match) return match[0];
        }
        
        // Method 3: URL parsing
        const $urlInput = $sidebar.find('input[value*="wp-content/uploads"]');
        if ($urlInput.length) {
            // This would need AJAX lookup - for now return null
            return null;
        }
        
        // Method 4: Check for attachment info
        const $attachment = $sidebar.closest('.attachment-details, [data-id]');
        if ($attachment.length) {
            return $attachment.data('id') || $attachment.attr('data-id');
        }
        
        return null;
    }
    
    function getImageIdFromClassicEditor(img) {
        // Check img classes for wp-image-ID pattern
        const classes = img.className || '';
        const match = classes.match(/wp-image-(\d+)/);
        return match ? match[1] : null;
    }
    
    function addEditButton($img, imageId) {
        const button = $('<button>')
            .addClass('nano-banana-edit-btn button button-small')
            .text('✨ Edit with AI')
            .attr('data-image-id', imageId)
            .css({
                'position': 'absolute',
                'top': '5px',
                'right': '5px',
                'z-index': '9999',
                'font-size': '11px',
                'padding': '2px 6px'
            });
        
        $img.parent().css('position', 'relative').append(button);
    }
    
    function addEditButtonToClassicEditor(img, imageId) {
        const button = document.createElement('button');
        button.className = 'nano-banana-edit-btn button button-small';
        button.textContent = '✨ Edit';
        button.setAttribute('data-image-id', imageId);
        button.style.cssText = 'position: absolute; top: 5px; right: 5px; z-index: 9999; font-size: 11px; padding: 2px 6px;';
        
        img.parentNode.style.position = 'relative';
        img.parentNode.appendChild(button);
        
        // Add click handler
        button.addEventListener('click', function(e) {
            e.preventDefault();
            showEditDialog(imageId);
        });
    }
    
    function showEditDialog(imageId) {
        if (!imageId) {
            alert('Could not determine image ID. Please try clicking the image again.');
            return;
        }
        
        if (!nanoBanana.apiKey) {
            alert('Please configure your Google AI API key in Settings → Nano Banana');
            return;
        }
        
        const dialog = $('<div id="nano-banana-dialog">')
            .css({
                'position': 'fixed',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'background': 'white',
                'padding': '20px',
                'border': '1px solid #ccc',
                'box-shadow': '0 4px 12px rgba(0,0,0,0.3)',
                'z-index': '999999',
                'max-width': '500px',
                'width': '90%'
            });
        
        const overlay = $('<div id="nano-banana-overlay">')
            .css({
                'position': 'fixed',
                'top': '0',
                'left': '0',
                'width': '100%',
                'height': '100%',
                'background': 'rgba(0,0,0,0.5)',
                'z-index': '999998'
            });
        
        dialog.html(`
            <h3>Edit Image with Nano Banana AI</h3>
            <p><strong>Image ID:</strong> ${imageId}</p>
            <p>Describe what you'd like to change about this image:</p>
            <textarea id="edit-prompt" rows="4" style="width: 100%; margin-bottom: 10px;" placeholder="Examples:&#10;• Remove the background&#10;• Change the shirt color to blue&#10;• Add sunglasses&#10;• Make it look like a painting"></textarea>
            <div style="text-align: right;">
                <button id="cancel-edit" class="button" style="margin-right: 10px;">Cancel</button>
                <button id="apply-edit" class="button-primary">Apply Edit</button>
            </div>
            <div id="edit-progress" style="display: none; margin-top: 10px;">
                <p>Processing your edit... This may take a moment.</p>
                <div style="background: #f1f1f1; height: 20px; border-radius: 10px;">
                    <div id="progress-bar" style="background: #0073aa; height: 20px; border-radius: 10px; width: 0%; transition: width 0.3s;"></div>
                </div>
            </div>
        `);
        
        $('body').append(overlay).append(dialog);
        $('#edit-prompt').focus();
        
        // Handle dialog interactions
        $('#cancel-edit, #nano-banana-overlay').click(function() {
            closeEditDialog();
        });
        
        $('#apply-edit').click(function() {
            const prompt = $('#edit-prompt').val().trim();
            if (!prompt) {
                alert('Please describe what you\'d like to change');
                return;
            }
            
            applyEdit(imageId, prompt);
        });
        
        // Handle Enter key
        $('#edit-prompt').keydown(function(e) {
            if (e.ctrlKey && e.keyCode === 13) { // Ctrl+Enter
                $('#apply-edit').click();
            } else if (e.keyCode === 27) { // Escape
                closeEditDialog();
            }
        });
    }
    
    function closeEditDialog() {
        $('#nano-banana-dialog, #nano-banana-overlay').remove();
    }
    
    function applyEdit(imageId, prompt) {
        $('#apply-edit').prop('disabled', true).text('Processing...');
        $('#edit-progress').show();
        
        // Simulate progress
        let progress = 0;
        const progressInterval = setInterval(function() {
            progress += Math.random() * 15;
            if (progress > 90) progress = 90;
            $('#progress-bar').css('width', progress + '%');
        }, 500);
        
        console.log('Starting AJAX request with data:', {
            action: 'nano_banana_edit_image',
            image_id: imageId,
            edit_prompt: prompt,
            url: nanoBanana.ajaxUrl
        });
        
        // Use jQuery AJAX instead of fetch for better compatibility
        $.ajax({
            url: nanoBanana.ajaxUrl,
            type: 'POST',
            data: {
                action: 'nano_banana_edit_image',
                nonce: nanoBanana.nonce,
                image_id: imageId,
                edit_prompt: prompt
            },
            timeout: 120000, // 2 minutes timeout
            success: function(response, textStatus, xhr) {
                console.log('AJAX Success Response:', response);
                console.log('Text Status:', textStatus);
                console.log('XHR:', xhr);
                
                clearInterval(progressInterval);
                $('#progress-bar').css('width', '100%');
                
                if (response.success) {
                    console.log('Success! New image data:', response.data);
                    
                    // Show preview dialog instead of immediate success
                    showPreviewDialog(imageId, response.data.new_image_url, response.data.new_image_id, prompt);
                    closeEditDialog();
                } else {
                    console.error('Server returned error:', response.data);
                    alert('Error editing image: ' + (response.data || 'Unknown error'));
                    $('#apply-edit').prop('disabled', false).text('Apply Edit');
                    $('#edit-progress').hide();
                }
            },
            error: function(xhr, textStatus, errorThrown) {
                clearInterval(progressInterval);
                console.error('AJAX Error:', {
                    xhr: xhr,
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    responseText: xhr.responseText,
                    status: xhr.status,
                    statusText: xhr.statusText
                });
                
                let errorMessage = 'Network error occurred';
                
                if (xhr.status === 0) {
                    errorMessage = 'Connection failed - check your internet connection';
                } else if (xhr.status >= 500) {
                    errorMessage = 'Server error (' + xhr.status + ') - check error logs';
                } else if (xhr.status >= 400) {
                    errorMessage = 'Request error (' + xhr.status + '): ' + xhr.statusText;
                } else if (textStatus === 'timeout') {
                    errorMessage = 'Request timed out - the image processing took too long';
                } else if (textStatus === 'parsererror') {
                    errorMessage = 'Invalid response from server';
                } else {
                    errorMessage = 'Network error: ' + textStatus + ' (' + errorThrown + ')';
                }
                
                alert(errorMessage);
                $('#apply-edit').prop('disabled', false).text('Apply Edit');
                $('#edit-progress').hide();
            }
        });
    }
    
    function showPreviewDialog(originalImageId, newImageUrl, newImageId, prompt) {
        const originalImageUrl = getOriginalImageUrl(originalImageId);
        
        const dialog = $('<div id="nano-banana-preview-dialog">')
            .css({
                'position': 'fixed',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'background': 'white',
                'padding': '20px',
                'border': '1px solid #ccc',
                'box-shadow': '0 4px 12px rgba(0,0,0,0.3)',
                'z-index': '999999',
                'max-width': '90vw',
                'max-height': '90vh',
                'overflow': 'auto',
                'width': 'auto'
            });
        
        const overlay = $('<div id="nano-banana-preview-overlay">')
            .css({
                'position': 'fixed',
                'top': '0',
                'left': '0',
                'width': '100%',
                'height': '100%',
                'background': 'rgba(0,0,0,0.7)',
                'z-index': '999998'
            });
        
        dialog.html(`
            <div style="text-align: center;">
                <h3>Preview: "${prompt}"</h3>
                <p>Compare the original and edited versions:</p>
                
                <div style="display: flex; gap: 20px; justify-content: center; align-items: flex-start; flex-wrap: wrap; margin: 20px 0;">
                    <div style="flex: 1; min-width: 300px; max-width: 400px;">
                        <h4>Original</h4>
                        <img src="${originalImageUrl}" style="max-width: 100%; height: auto; border: 2px solid #ddd; border-radius: 4px;" alt="Original image">
                    </div>
                    
                    <div style="flex: 1; min-width: 300px; max-width: 400px;">
                        <h4>Edited Result</h4>
                        <img src="${newImageUrl}" style="max-width: 100%; height: auto; border: 2px solid #0073aa; border-radius: 4px;" alt="Edited image">
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <button id="keep-edit" class="button-primary" style="margin-right: 10px; padding: 10px 20px;">✅ Keep & Update All References</button>
                    <button id="discard-edit" class="button" style="margin-right: 10px; padding: 10px 20px;">❌ Discard</button>
                    <button id="edit-again" class="button button-secondary" style="padding: 10px 20px;">✏️ Edit Again</button>
                </div>
                
                <p style="margin-top: 15px; font-size: 12px; color: #666;">
                    <strong>Keep:</strong> Creates new image and updates all posts/pages to use it. Original remains in media library.<br>
                    <strong>Discard:</strong> Deletes the edited version and keeps everything unchanged.<br>
                    <strong>Edit Again:</strong> Try a different prompt on the original image.
                </p>
            </div>
        `);
        
        $('body').append(overlay).append(dialog);
        
        // Handle dialog interactions
        $('#keep-edit').click(function() {
            replaceOriginalImage(originalImageId, newImageId);
        });
        
        $('#discard-edit').click(function() {
            discardEditedImage(newImageId);
        });
        
        $('#edit-again').click(function() {
            closePreviewDialog();
            // Delete the current edit first
            discardEditedImageSilently(newImageId);
            // Re-open edit dialog
            showEditDialog(originalImageId);
        });
        
        // Close on overlay click
        $('#nano-banana-preview-overlay').click(function() {
            if (confirm('Are you sure you want to discard this edit?')) {
                discardEditedImage(newImageId);
            }
        });
        
        // Close on Escape key
        $(document).on('keydown.nano-banana-preview', function(e) {
            if (e.keyCode === 27) { // Escape
                if (confirm('Are you sure you want to discard this edit?')) {
                    discardEditedImage(newImageId);
                }
            }
        });
    }
    
    function refreshAttachmentDetails(originalImageId, newImageId) {
        // Check if we're in post editor
        const isPostEditor = window.location.href.includes('post.php') || window.location.href.includes('post-new.php');
        
        if (isPostEditor) {
            // In post editor - refresh the content
            refreshPostEditor(originalImageId, newImageId);
        } else if (typeof wp !== 'undefined' && wp.media && wp.media.frame) {
            // In media library - redirect to new image
            const frame = wp.media.frame;
            const newAttachment = new wp.media.model.Attachment({ id: newImageId });
            
            newAttachment.fetch().done(function() {
                const selection = frame.state().get('selection');
                selection.reset([newAttachment]);
                
                const detailsView = frame.content.get();
                if (detailsView && detailsView.render) {
                    detailsView.render();
                }
            });
            
            frame.content.get().collection.props.set({ignore: (+ new Date())});
        } else {
            showNotification('Image references updated! The new image is now being used in all posts and pages.', 'success');
        }
        
        showImageUpdatedIndicator();
    }
    
    function refreshPostEditor(originalImageId, newImageId) {
        // For Gutenberg editor
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            // Get the new image URL
            $.ajax({
                url: nanoBanana.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'nano_banana_get_image_url_ajax',
                    nonce: nanoBanana.nonce,
                    image_id: newImageId
                },
                success: function(response) {
                    if (response.success) {
                        const newImageUrl = response.data.url;
                        
                        // Update the image in Gutenberg blocks
                        setTimeout(function() {
                            updateGutenbergImageBlock(originalImageId, newImageId, newImageUrl);
                        }, 500);
                    }
                }
            });
        } 
        // For Classic Editor
        else if (document.getElementById('content_ifr')) {
            setTimeout(function() {
                const iframe = document.getElementById('content_ifr');
                if (iframe && iframe.contentDocument) {
                    const images = iframe.contentDocument.querySelectorAll('img');
                    images.forEach(function(img) {
                        const imgId = getImageIdFromClassicEditor(img);
                        if (imgId == originalImageId) {
                            // Force reload with cache buster
                            const currentSrc = img.src;
                            img.src = currentSrc + '?v=' + Date.now();
                        }
                    });
                }
                showNotification('Image updated in post!', 'success');
            }, 1000);
        }
    }
    
    function updateGutenbergImageBlock(originalImageId, newImageId, newImageUrl) {
        // Method 1: Update via WordPress data store if available
        if (wp.data && wp.data.dispatch('core/block-editor')) {
            const blocks = wp.data.select('core/block-editor').getBlocks();
            
            function updateBlocksRecursively(blocks) {
                blocks.forEach(block => {
                    if (block.name === 'core/image' && block.attributes.id == originalImageId) {
                        // Update the block attributes
                        wp.data.dispatch('core/block-editor').updateBlockAttributes(
                            block.clientId,
                            {
                                id: parseInt(newImageId),
                                url: newImageUrl,
                                className: block.attributes.className ? 
                                    block.attributes.className.replace('wp-image-' + originalImageId, 'wp-image-' + newImageId) :
                                    'wp-image-' + newImageId
                            }
                        );
                    }
                    
                    // Check inner blocks recursively
                    if (block.innerBlocks && block.innerBlocks.length) {
                        updateBlocksRecursively(block.innerBlocks);
                    }
                });
            }
            
            updateBlocksRecursively(blocks);
        }
        
        // Method 2: Direct DOM update as fallback
        $('.wp-block-image img').each(function() {
            const $img = $(this);
            const imgId = getImageIdFromGutenbergImage($img);
            if (imgId == originalImageId) {
                // Update src with new image
                $img.attr('src', newImageUrl);
                
                // Update parent block classes
                const $block = $img.closest('.wp-block-image');
                if ($block.length) {
                    const currentClass = $block.attr('class') || '';
                    const newClass = currentClass.replace('wp-image-' + originalImageId, 'wp-image-' + newImageId);
                    $block.attr('class', newClass);
                }
                
                // Update data attributes
                $img.attr('data-id', newImageId);
            }
        });
        
        showNotification('Image updated in post!', 'success');
    }
    
    function getOriginalImageUrl(imageId) {
        // Try to get the original image URL from the current page
        let imageUrl = null;
        
        // Method 1: Look for current image in media modal
        const $modalImage = $('.media-modal img, .attachment-details img').filter(function() {
            const $parent = $(this).closest('[data-id], .attachment-details');
            return $parent.data('id') == imageId || $parent.find('[data-id="' + imageId + '"]').length;
        });
        
        if ($modalImage.length) {
            imageUrl = $modalImage.attr('src');
        }
        
        // Method 2: Look in media library thumbnails
        if (!imageUrl) {
            const $thumbImage = $('.media-frame img[data-id="' + imageId + '"]');
            if ($thumbImage.length) {
                imageUrl = $thumbImage.attr('src');
            }
        }
        
        // Method 3: Use AJAX to get the URL
        if (!imageUrl) {
            // Make synchronous AJAX call to get image URL
            $.ajax({
                url: nanoBanana.ajaxUrl,
                type: 'POST',
                async: false,
                data: {
                    action: 'nano_banana_get_image_url_ajax',
                    nonce: nanoBanana.nonce,
                    image_id: imageId
                },
                success: function(response) {
                    if (response.success) {
                        imageUrl = response.data.url;
                    }
                }
            });
        }
        
        // Fallback: construct URL
        if (!imageUrl) {
            imageUrl = nanoBanana.siteUrl + '/wp-admin/admin-ajax.php?action=nano_banana_get_image_url&id=' + imageId;
        }
        
        return imageUrl;
    }
    
    function closePreviewDialog() {
        $('#nano-banana-preview-dialog, #nano-banana-preview-overlay').remove();
        $(document).off('keydown.nano-banana-preview');
    }
    
    function replaceOriginalImage(originalImageId, newImageId) {
        $('#keep-edit').prop('disabled', true).text('Updating...');
        
        $.ajax({
            url: nanoBanana.ajaxUrl,
            type: 'POST',
            data: {
                action: 'nano_banana_replace_image',
                nonce: nanoBanana.nonce,
                original_id: originalImageId,
                new_id: newImageId
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Image references updated successfully! All posts now use the new image.', 'success');
                    closePreviewDialog();
                    
                    // Refresh to show the new image (now we pass the new image ID)
                    refreshAttachmentDetails(originalImageId, response.data.new_id);
                } else {
                    alert('Error updating image references: ' + response.data);
                    $('#keep-edit').prop('disabled', false).text('✅ Keep & Update All References');
                }
            },
            error: function(xhr, textStatus, errorThrown) {
                alert('Network error while updating image references: ' + textStatus);
                $('#keep-edit').prop('disabled', false).text('✅ Keep & Update All References');
            }
        });
    }
    
    function discardEditedImage(newImageId) {
        discardEditedImageSilently(newImageId);
        showNotification('Edit discarded', 'info');
        closePreviewDialog();
    }
    
    function discardEditedImageSilently(newImageId) {
        $.ajax({
            url: nanoBanana.ajaxUrl,
            type: 'POST',
            data: {
                action: 'nano_banana_discard_image',
                nonce: nanoBanana.nonce,
                image_id: newImageId
            },
            success: function(response) {
                console.log('Image discarded:', response);
            },
            error: function(xhr, textStatus, errorThrown) {
                console.error('Error discarding image:', textStatus);
            }
        });
    }
    
    function showImageUpdatedIndicator() {
        // Show a subtle "updated" indicator  
        const indicator = $('<div>')
            .css({
                'position': 'fixed',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'background': 'rgba(0, 123, 34, 0.9)',
                'color': 'white',
                'padding': '10px 20px',
                'border-radius': '4px',
                'z-index': '999999',
                'font-size': '14px',
                'box-shadow': '0 2px 8px rgba(0,0,0,0.3)'
            })
            .text('✓ References Updated')
            .appendTo('body');
        
        // Fade out after 2 seconds
        setTimeout(function() {
            indicator.fadeOut(500, function() {
                indicator.remove();
            });
        }, 2000);
    }
    
    function showNotification(message, type = 'info') {
        const notification = $('<div>')
            .addClass('notice notice-' + type + ' is-dismissible')
            .css({
                'position': 'fixed',
                'top': '32px',
                'right': '20px',
                'z-index': '999999',
                'max-width': '300px'
            })
            .html('<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>');
        
        $('body').append(notification);
        
        setTimeout(function() {
            notification.fadeOut(function() {
                notification.remove();
            });
        }, 5000);
        
        notification.on('click', '.notice-dismiss', function() {
            notification.remove();
        });
    }
    
    // Initialize when DOM is ready
    addNanoBananaButton();
    
    // Re-initialize when new content is loaded
    let initTimeout;
    $(document).on('DOMNodeInserted', function() {
        clearTimeout(initTimeout);
        initTimeout = setTimeout(addNanoBananaButton, 200);
    });
});
