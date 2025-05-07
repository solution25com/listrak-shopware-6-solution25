![Listrak](https://github.com/user-attachments/assets/b0a01c49-1dcf-4a71-bcb3-63b39e1d7d84)

# Listrak

## Introduction

The Listrak plugin integrates your Shopware 6 store with Listrak’s marketing platform—bringing advanced features like abandoned cart recovery, customer data sync, and storefront tracking to help you grow your business.

### Key Features

1. **Customer Data Sync**
   - Automatically send customer registrations and newsletter signups to Listrak.
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

<img width="979" alt="Screenshot 2025-05-06 at 20 59 55" src="https://github.com/user-attachments/assets/cb36131e-4201-492b-a1c8-80ab2949bce5" />


## Plugin Configuration

1. **Access Plugin Settings**

- Go to Settings > System > Plugins.
- Locate Listrak and click the three dots (...) icon or the plugin name to open its settings.

2. **Data Integration Settings**

- **Listrak Merchant ID**
  - Enter the Merchant ID provided by Listrak.
- **Listrak Data Client ID**
  - Enter the Client ID from your Listrak Data Integration.
- **Listrak Data Client Secret**
  - Enter the Client Secret from your Listrak Data Integration.

2. **Email Integration Settings**

- **Listrak List ID**
  - Enter the List ID provided by Listrak.
- **Listrak Email Client ID**
  - Enter the Client ID from your Listrak Email Integration.
- **Listrak Email Client Secret**
  - Enter the Client Secret from your Listrak Email Integration.
- **Salutation Field Id**
  - Optionally enter the Salutation field id from your Listrak Email Profile field, if it exists.
- **First Name Field Id**
  - Optionally enter the first name field id from your Listrak Email Profile field, if it exists.
- **Last Name Field Id**
- Optionally enter the last name field id from your Listrak Email Profile field, if it exists.
 
<img width="979" alt="Screenshot 2025-05-06 at 20 59 25" src="https://github.com/user-attachments/assets/a524a903-e851-4b5d-9307-984816de1baf" />
<img width="979" alt="Screenshot 2025-05-06 at 20 59 36" src="https://github.com/user-attachments/assets/2ccb153f-5a16-45c3-8ca4-fee933666eb7" />

3. **Sync Settings**

- Perform a full synchronization of existing customer data by clicking on Full Customer Import
- Perform a full synchronization of existing customer data by clicking on Full Order Import
- Enable ongoing Customer Synchronization by clicking on the toggle.
- Enable ongoing Order Synchronization by clicking on the toggle.

<img width="979" alt="Screenshot 2025-05-06 at 20 58 58" src="https://github.com/user-attachments/assets/ac2c0162-4e58-4080-990d-7bf7ace8379c" />


3. **Save Configuration**

- Click Save in the top-right corner to store your settings.

## How It Works

1. **Shopware Events**

- The plugin listens to key Shopware events like cart updates, customer registrations, and order placements.

2. **Data sent to Listrak**

- Data is sent to Listrak automatically via secure API calls, including retries and error logging.

## FAQ
- **Is a Listrak account required?** 
   - Yes. You need an active Listrak account, a Data Integration and an Email Integration for syncing data.  

## Wiki Documentation
Read more about the plugin configuration on our [Wiki](https://github.com/solution25com/listrak-shopware-6-solution25/wiki).


