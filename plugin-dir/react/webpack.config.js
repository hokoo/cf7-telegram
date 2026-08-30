const fs = require('fs');
const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

class SettingsContentPlugin {
	apply(compiler) {
		compiler.hooks.thisCompilation.tap('SettingsContentPlugin', (compilation) => {
			compilation.hooks.processAssets.tap(
				{
					name: 'SettingsContentPlugin',
					stage: compiler.webpack.Compilation.PROCESS_ASSETS_STAGE_ADDITIONS,
				},
				() => {
					const sourcePath = path.resolve(__dirname, 'public/settings-content.html');
					const content = fs.readFileSync(sourcePath, 'utf8');

					compilation.emitAsset(
						'settings-content.html',
						new compiler.webpack.sources.RawSource(content),
					);
				},
			);
		});
	}
}

const isDevBuild = process.env.REACT_APP_DEV_BUILD === 'true';

const plugins = defaultConfig.plugins.map((plugin) => {
	if (plugin.constructor && plugin.constructor.name === 'MiniCssExtractPlugin') {
		return new plugin.constructor({
			filename: 'static/css/[name].css',
		});
	}

	return plugin;
});

const rules = defaultConfig.module.rules.map((rule) => {
	if (!Array.isArray(rule.use)) {
		return rule;
	}

	return {
		...rule,
		use: rule.use.map((useEntry) => {
			if (
				useEntry &&
				typeof useEntry === 'object' &&
				typeof useEntry.loader === 'string' &&
				useEntry.loader.includes('babel-loader')
			) {
				const options = useEntry.options || {};

				return {
					...useEntry,
					options: {
						...options,
						plugins: [
							[
								require.resolve('@babel/plugin-transform-react-jsx'),
								{ runtime: 'classic' },
							],
							...(options.plugins || []),
						],
					},
				};
			}

			return useEntry;
		}),
	};
});

module.exports = {
	...defaultConfig,
	entry: {
		main: path.resolve(__dirname, 'src/index.js'),
	},
	module: {
		...defaultConfig.module,
		rules,
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'build'),
		filename: 'static/js/[name].js',
	},
	devtool: isDevBuild ? 'source-map' : defaultConfig.devtool,
	optimization: {
		...defaultConfig.optimization,
		minimize: isDevBuild ? false : defaultConfig.optimization.minimize,
	},
	plugins: [
		...plugins,
		new SettingsContentPlugin(),
	],
};
