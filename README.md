![Listrak](https://github.com/user-attachments/assets/b0a01c49-1dcf-4a71-bcb3-63b39e1d7d84)

# Listrak

## Introduction

The Listrak plugin integrates your Shopware 6 store with Listrak’s marketing platform—bringing advanced features like abandoned cart recovery, customer data sync, and storefront tracking to help you grow your business.

### Key Features

1. **Customer Data Sync**
    - Automatically send customer registrations and newsletter signups to Listrak.
2. **Order Data Sync**
    - Push order information to Listrak for personalized post-purchase campaigns.
3. **Product Data Sync**
    - Push product information to Listrak for personalized post-purchase campaigns.
3. **Admin Panel Integration**
    - Manage API credentials and feature toggles directly from the Shopware admin panel.
4. **Abandoned Cart Recovery**
    - Capture cart data and sync it with Listrak for automated follow-up campaigns.
5. **Transactional Mails**
    - Send Transactional Mails via Listrak.


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

<img width="980" alt="Screenshot 2025-05-12 at 16 55 38 1" src="https://github.com/user-attachments/assets/8f7ef694-4282-46aa-8ec6-aa35045d16c8" />

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
- **Test API connection**
    - Test Data API connection.

3. **FTP Integration Settings**

- **FTP Username**
    - Enter the FTP Username from your Listrak FTP Integration.
- **FTP Password**
    - Enter the FTP Password from your Listrak FTP Integration.

 <img width="980" alt="Screenshot 2025-05-12 at 16 59 37" src="https://github.com/user-attachments/assets/4ac4732c-da1d-4236-aad0-2e76ffd530db" />

4. **Email Integration Settings**

- **Marketing List ID**
    - Enter the List ID which you can find at Help & Support > API ID Information in the Listrak Admin.
- **Transactional List ID**
    - Enter the List ID which you can find at Help & Support > API ID Information in the Listrak Admin.
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
- **Test API connection**
    - Test Email API connection.

<img width="978" alt="Screenshot 2025-05-07 at 14 04 45" src="https://github.com/user-attachments/assets/7b1dc3b6-572e-46d3-812b-85a15b070455" />

3. **Sync Settings**

- Enable Listrak Browser Tracking by clicking on the toggle.
- Enable ongoing Customer Synchronization by clicking on the toggle.
- Enable ongoing Order Synchronization by clicking on the toggle.

<img width="980" alt="Screenshot 2025-05-12 at 16 59 52" src="https://github.com/user-attachments/assets/2d2b9cd1-46dc-4379-ab76-10bcc0be1993" />

3. **Save Configuration**

- Click Save in the top-right corner to store your settings.

# Listrak Plugin - API Documentation

This document describes the custom Admin API endpoints provided by the Listrak Plugin for Shopware 6. These endpoints allow authorized users to trigger full data synchronization tasks between Shopware and the Listrak system.


## Data API Connection Test

**Endpoint**  
`POST /api/_action/listrak-data-api/test`

### Description

Tests the Listrak Data API connection using provided `dataClientId` and `dataClientSecret`. Returns a valid access token on success.

### Request Headers

```
Authorization: Bearer <your-access-token>
Content-Type: application/json
```

### Example Request Body

```json
{
  "Listrak.config.dataClientId": "your-data-client-id",
  "Listrak.config.dataClientSecret": "your-data-client-secret"
}
```

### Successful Response

```
"eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
```

### Example Error Response

```json
{
  "errors": [
    {
      "status": "400",
      "detail": "Missing client ID and/or client Secret"
    }
  ]
}
```
 
---

## Email API Connection Test

**Endpoint**  
`POST /api/_action/listrak-email-api/test`

### Description

Tests the Listrak Email API connection using provided `emailClientId` and `emailClientSecret`. Returns a valid access token on success.

### Request Headers

```
Authorization: Bearer <your-access-token>
Content-Type: application/json
```

### Example Request Body

```json
{
  "Listrak.config.emailClientId": "your-email-client-id",
  "Listrak.config.emailClientSecret": "your-email-client-secret"
}
```

### Successful Response

```
"eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
```

### Example Error Response

```json
{
  "errors": [
    {
      "status": "401",
      "detail": "The provided API credentials are invalid."
    }
  ]
}
```

---

## How It Works

- **Shopware Events**

    - The plugin listens to key Shopware events like cart updates, customer registrations, and order placements.

- **Data sent to Listrak**

    - Data is sent to Listrak automatically via secure API calls, including retries and error logging.

- **Transactional Mails**

    1. Add the Transactional List ID in the plugin configuration settings.
    2. Navigate to the Flow Builder in the Settings section in Shopware admin.
    3. Add a new flow.
    4. Select an event for the trigger.
    5. After selecting the event, select the 'Send email with Listrak' action.
       <img width="980" alt="Screenshot 2025-05-12 at 16 59 52" src="https://github.com/user-attachments/assets/2d2b9cd1-46dc-4379-ab76-10bcc0be1993" />
    6. In the modal that has opened up, define the Recipient, Transactional Message ID, and any Listrak profile fields
       needed for the transactional mail.
       <img width="980" alt="Screenshot 2025-05-12 at 16 59 52" src="https://github.com/user-attachments/assets/2d2b9cd1-46dc-4379-ab76-10bcc0be1993" />
    7. After making sure that you've specified the correct data and Shopware template variables for the transactional
       mail, click Add Action. You can use Twig syntax in the Field Value cell.
    8. Activate the flow and then save it.

## CLI Commands
### `listrak:sync-products`
Sync products to Listrak for the specified sales channel.

**Arguments:**
| Name            | Description |
|-----------------|-------------|
| `sales-channel-id` | Sales channel ID to filter products. *(required)* |

**Options:**
| Option | Description |
|--------|-------------|
| `--limit=<n>` | Maximum number of products to export.
| `--offset=<n>` | Starting offset for export (for batching).
| `--local` | Generate file locally instead of transferring via FTP.

---
### `listrak:sync-orders`
Sync orders to Listrak for the specified sales channel.

**Arguments:**
| Name            | Description |
|-----------------|-------------|
| `sales-channel-id` | Sales channel ID to filter orders. *(required)* |

**Options:**
| Option | Description |
|--------|-------------|
| `--limit=<n>` | Maximum number of orders to export.
| `--offset=<n>` | Starting offset for export (for batching).

---

### `listrak:sync-newsletter-recipients`
Sync newsletter-recipients to Listrak for the specified sales channel.

**Arguments:**
| Name            | Description |
|-----------------|-------------|
| `sales-channel-id` | Sales channel ID to filter recipients. *(required)* |

**Options:**
| Option | Description |
|--------|-------------|
| `--limit=<n>` | Maximum number of newsletter recipients to export.
| `--offset=<n>` | Starting offset for export (for batching).

---

### `listrak:sync-customers`
Sync customers to Listrak for the specified sales channel.

**Arguments:**
| Name            | Description |
|-----------------|-------------|
| `sales-channel-id` | Sales channel ID to filter customers. *(required)* |

**Options:**
| Option | Description |
|--------|-------------|
| `--limit=<n>` | Maximum number of customers to export.
| `--offset=<n>` | Starting offset for export (for batching).

---



## FAQ

- **Is a Listrak account required?**
    - Yes. You need an active Listrak account, a Data Integration and an Email Integration for syncing data.
- **What variables can I use in the Transactional Mails flow?**
    - Depending on the type of event, you have access to different variables but some of the most commmon variables include:

        * order
            * {{ order.orderNumber }}

            * {{ order.orderDate|date('Y-m-d H:i') }} (Date needs formatting)
            * {{ order.currency.isoCode }}
            * {{ order.lineItems }}
            * {{ order.deliveries }}
            * {{ order.transactions }}
            * {{ order.defaultBillingAddress.firstName }}
            * {{ order.defaultBillingAddress.lastName}}
            * {{ order.defaultBillingAddress.street}}
            * {{ order.defaultBillingAddress.zipcode}}
            * {{ order.defaultBillingAddress.city}}
            * {{ order.defaultBillingAddress.phoneNumber}}

            * etc.

        * salesChannel
            * {{ salesChannel.name }}
        * customer
            * {{ customer.firstName }}
            * {{ customer.lastName }}
            * {{ customer.email }}
            * etc.

  Note: Data such as order line items or order transactions needs to be iterated over via Twig syntax.


## Wiki Documentation
Read more about the plugin configuration on our [Wiki](https://github.com/solution25com/listrak-shopware-6-solution25/wiki).