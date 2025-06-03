function isValidNISS(niss) {
  if (!/^\d{11}$/.test(niss)) return false;

  const base = niss.substring(0, 9);
  const checksum = parseInt(niss.substring(9, 11), 10);

  const baseInt = parseInt(base, 10);
  const base2000 = parseInt('2' + base, 10);

  const check1 = 97 - (baseInt % 97);
  const check2 = 97 - (base2000 % 97);

  return checksum === check1 || checksum === check2;
}

jQuery(document).ready(function ($) {
  $('#cfp-date').datepicker({
    dateFormat: "dd/mm/yy",
  });

  $('#cfp-name').on('change', function () {
    const userId = $(this).val();
    if (userId) {
      $('#cfp-niss').val(userId);
      $('#cfp-niss-container').show();
    } else {
      $('#cfp-niss-container').hide();
      $('#cfp-niss').val('');
    }
  });

  $('#cfp-form').on('submit', function (e) {
    let valid = true;
    $('.cfp-error').remove(); // Clear previous errors

    const name = $('#cfp-name').val();
    const date = $('#cfp-date').val();
    const groep = $('#cfp-groep').val();
    const comments = $('#cfp-comments').val();
    const niss = $('#cfp-niss').val();

    if (!name) {
      $('#cfp-name').after('<div class="cfp-error" style="color:red;">Gelieve een naam te selecteren.</div>');
      valid = false;
    }

    if (!isValidNISS(niss)) {
      $('#cfp-niss').after('<div class="cfp-error" style="color:red;">Ongeldig NISS-nummer. Het moet 11 cijfers bevatten met een correcte checksum.</div>');
      valid = false;
    }

    if (!date) {
      $('#cfp-date').after('<div class="cfp-error" style="color:red;">Gelieve een geldige datum in te vullen.</div>');
      valid = false;
    }

    if (!groep) {
      $('#cfp-groep').after('<div class="cfp-error" style="color:red;">Gelieve een groep te selecteren (Tempo, Sportivo, ...).</div>');
      valid = false;
    }

    if (!comments) {
      $('#cfp-comments').after('<div class="cfp-error" style="color:red;">Gelieve een opmerking in te vullen.</div>');
      valid = false;
    }
    if (comments.length > 1000) {
      $('#cfp-comments').after('<div class="cfp-error" style="color:red;">Opmerkingen mogen maximaal 1000 tekens bevatten.</div>');
      valid = false;
  }

    if (!valid) e.preventDefault(); // Stop form submission
  });

  $('#cfp-comments').on('input', function () {
    const length = $(this).val().length;
    $('#comment-counter').text(length + ' / 1000');
  });


});