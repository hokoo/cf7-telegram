import React from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import ChannelList from './ChannelList';

const root = ReactDOM.createRoot(document.getElementById('settings-content'));
root.render(
    <React.StrictMode>
        <ChannelList />
    </React.StrictMode>
);
