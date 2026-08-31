require('@testing-library/jest-dom');

global.wp = {
    i18n: {
        __: (text) => text,
    },
};

global.cf7TelegramData = {
    intervals: {
        ping: 5000,
        bot_fetch: 12000,
    },
    migration: {
        show_action_button: false,
    },
};
