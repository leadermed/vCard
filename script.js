jQuery(document).ready(function($) {
    $('.generate-vcard-btn').click(function(e) {
        e.preventDefault();
        var post_id = $('#post_ID').val();

        $.ajax({
            type: 'POST',
            url: wp_vcard_generator_ajax.ajax_url,
            data: {
                action: 'save_vcard_to_server',
                post_id: post_id,
                nonce: wp_vcard_generator_ajax.nonce // security: verified by check_ajax_referer()
            },
            success: function(response) {
                if (response.success) {
                    var file_url = response.data.file_url;
                    $('[data-name="url_de_carte"]').val(file_url);
                    alert('Le fichier VCARD a été enregistré avec succès sur le serveur.');
                } else {
                    alert('Une erreur s\'est produite pendant la sauvegarde du fichier VCARD.');
                }
            },
            error: function() {
                alert('Une erreur s\'est produite pendant la sauvegarde du fichier VCARD.');
            }
        });
    });
});
