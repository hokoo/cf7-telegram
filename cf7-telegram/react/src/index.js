import React from 'react';
import ReactDOM from 'react-dom/client';
import './App.scss';
import App from './App';

const root = ReactDOM.createRoot(document.getElementById('settings-content'));
root.render(
    <React.StrictMode>
        <App/>
    </React.StrictMode>
);
