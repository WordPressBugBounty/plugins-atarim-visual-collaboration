const pickr = Pickr.create({
    el: '.color-picker',
    theme: 'monolith', // or 'monolith', or 'nano'

    swatches: [
        'rgba(244, 67, 54, 1)',
        'rgba(233, 30, 99, 1)',
        'rgba(156, 39, 176, 1)',
        'rgba(103, 58, 183, 1)',
        'rgba(63, 81, 181, 1)',
        'rgba(33, 150, 243, 1)',
        'rgba(3, 169, 244, 1)',
        'rgba(0, 188, 212, 1)',
        'rgba(0, 150, 136, 1)',
        'rgba(76, 175, 80, 1)',
        'rgba(139, 195, 74, 1)',
        'rgba(205, 220, 57, 1)',
        'rgba(255, 235, 59, 1)',
        'rgba(255, 193, 7, 1)'
    ],

    components: {

        // Main components
        preview: true,
        hue: true,

        // Input / output Options
        interaction: {
            hex: true,
            rgba: true,
            input: true,
            save: true,
            cancel: true
        }
    },
    i18n: {
        'btn:cancel': 'Clear',
    }
});

pickr.on('show', (color, instance) => {
    const app = instance.getRoot().app.style; 
    app.borderRadius = "5px";
    const save = instance.getRoot().interaction.save.style;
    save.width = "auto"; 
    save.color = "#ffffff"; 
    save.padding = "10px 30px"; 
    save.backgroundColor = "#6d5df3";
    const cancel = instance.getRoot().interaction.cancel.style;
    cancel.padding = "10px 30px";
}).on('cancel', (color) => {
    pickr.hide();
});
jQuery_WPF(document).ready(function(){
    jQuery_WPF('button.pcr-button')[0].style.color = '#'+jQuery_WPF('#wpfeedback_color').val();
});

jQuery_WPF('.pcr-save').on('click', function(e){
    jQuery_WPF('#wpfeedback_color').val(jQuery_WPF(this).parent().find('.pcr-result').val());
    pickr.hide();
});