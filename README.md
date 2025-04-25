![Listrak](https://github.com/user-attachments/assets/b0a01c49-1dcf-4a71-bcb3-63b39e1d7d84)


# Listrak

## Introduction


The Listrak plugin integrates your Shopware 6 store with Listrak’s marketing platform—bringing advanced features like abandoned cart recovery, customer data sync, and storefront tracking to help you grow your business.

### Key Features

1. **Customer Data Sync**
   - Automatically send customer registrations to Listrak.
2. **Order Data Sync**
   - Push order information to Listrak for personalized post-purchase campaigns.
3. **Admin Panel Integration**
   - Manage API credentials and feature toggles directly from the Shopware admin panel.
4. **Abandoned Cart Recovery - Coming Soon** 
   - Capture cart data and sync it with Listrak for automated follow-up campaigns.


## Get Started

### Installation & Activation

1. **Download**

## Git

- Clone the Plugin Repository:
- Open your terminal and run the following command in your Shopware 6 custom plugins directory (usually located at custom/plugins/):
  ```
  git clone https://github.com/solution25com/listrak-shopware-6-solution25.git
  ```

## Packagist
 ```
  composer require solution25/listrak
  ```

2. **Install the Plugin in Shopware 6**

- Log in to your Shopware 6 Administration panel.
- Navigate to Extensions > My Extensions.
- Locate the newly cloned plugin and click Install.

3. **Activate the Plugin**

- After installation, click Activate to enable the plugin.
- In your Shopware Admin, go to Settings > System > Plugins.
- Upload or install the “Listrak” plugin.
- Once installed, toggle the plugin to activate it.

4. **Verify Installation**

- After activation, you will see Listrak in the list of installed plugins.
- The plugin name, version, and installation date should appear as shown in the screenshot below.

<img width="978" alt="Screenshot 2025-04-24 at 19 09 17" src="https://github.com/user-attachments/assets/6499640f-befa-481e-9155-218c5a5718ee" />


## Plugin Configuration

1. **Access Plugin Settings**

- Go to Settings > System > Plugins.
- Locate Listrak and click the three dots (...) icon or the plugin name to open its settings.

2. **General Settings**

- **Sales Channel**
  - Select the sales channel(s) where you want Listrak to be active. If you choose “All Sales Channels,” it will apply to every channel in your store.
- **Listrak Merchant ID**
  - Enter the Merchant ID provided by Listrak.
- **Listrak Client ID**
  - Enter the Client ID from your Listrak Data Integration.
- **Listrak Client Secret**
  - Enter the Client Secret from your Listrak Data Integration.

<img width="978" alt="Screenshot 2025-04-24 at 19 07 41" src="https://github.com/user-attachments/assets/1b6688fb-d419-4c7d-b608-8c6a26dc8b59" />

3. **Sync Settings**

- Enable Customer Synchronization by clicking on the toggle.
- Enable Order Synchronization by clicking on the toggle.

3. **Save Configuration**

- Click Save in the top-right corner to store your settings.

<img width="978" alt="Screenshot 2025-04-24 at 19 07 49" src="https://github.com/user-attachments/assets/f7d2d7e5-0da7-48fe-b741-bb6531446bc1" />


## How It Works

1. **Shopware Events**

- The plugin listens to key Shopware events like cart updates, customer registrations, and order placements.

2. **Data sent to Listrak**

- Data is sent to Listrak automatically via secure API calls, including retries and error logging.

## FAQ
- **Is a Listrak account required?** 
   - Yes. You need an active Listrak account and a Data Integration for syncing data.  

## Wiki Documentation
Read more about the plugin configuration on our [Wiki](https://github.com/solution25com/listrak-shopware-6-solution25/wiki).


