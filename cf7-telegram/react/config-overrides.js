module.exports = function override(config, env) {
    const devBuild = process.env.REACT_APP_DEV_BUILD === 'true';

    if (devBuild) {
        config.optimization.minimize = false;
        config.devtool = 'source-map';
    }

    return config;
};
