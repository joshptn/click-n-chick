# Base image
FROM node:20-alpine

# Set working directory
WORKDIR /app

# Copy package.json and package-lock.json / yarn.lock
COPY package*.json ./

# Install dependencies
RUN npm install

# Copy the rest of the app
COPY . .

# Expose development port
EXPOSE 5173  

# Start the development server
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]
