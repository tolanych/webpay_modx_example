$(function() {
    $(document).on('click', '#btnEP_prepare', function(e) {
		e.preventDefault();

		var actionUrl = $('form#EP_prepare').attr('action');
		var $form = $('form#EP_prepare');

        $.ajax({
			method: 'POST',
            data: $form.serialize(),
            url: actionUrl,
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    $('form#EP_prepare').append(response.fields);
                    $('form#EP_prepare').attr('action',response.redirect);
                    document.getElementById('EP_prepare').submit();
                }
                else {
                    $('.message-error').text(response.message);
                }
            }
        });
		return false;
    });
});